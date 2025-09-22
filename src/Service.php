<?php

declare(strict_types=1);

namespace App;

use GuzzleHttp\Exception\GuzzleException;
use Vjik\TelegramBot\Api\Type\InlineKeyboardButton;
use Vjik\TelegramBot\Api\Type\InlineKeyboardMarkup;

class Service
{
    public function __construct(
        private KeeneticApi $keenetic,
        private Telegram    $tg
    )
    {
        $this->keenetic->auth();
    }

    /**
     * Обработчик входящих обновлений Telegram
     *
     * - Обрабатывает нажатия inline-кнопок и переключает политику устройств
     * - Формирует inline-клавиатуру с любимыми устройствами
     * - Добавляет эмодзи для отображения текущей политики
     *
     * @return void
     * @throws GuzzleException
     */
    public function handle(): void
    {
        $update = $this->tg->getUpdate();

        if ($update === null) {
            return;
        }

        $devices = $this->keenetic->getDevices();
        $favDevices = $this->keenetic->getFavDevices($devices);

        if ($update->callbackQuery !== null) {
            $this->handleCallbackQuery($update->callbackQuery, $favDevices);
            return;
        }

        if ($update->message !== null) {
            $this->handleMessage($update->message, $favDevices);
        }
    }

    /**
     *  Обрабатывает нажатие на inline-кнопку.
     *
     *  Берёт chat_id и message_id из callbackQuery, меняет политику устройства
     *  через Keenetic API, перерисовывает inline-клавиатуру и отправляет ответ пользователю.
     * @param object $callbackQuery
     * @param array $favDevices
     * @return void
     * @throws GuzzleException
     */
    private function handleCallbackQuery(object $callbackQuery, array $favDevices): void
    {
        $chatId = $callbackQuery->message->chat->id;
        $messageId = $callbackQuery->message->messageId;

        $mac = $callbackQuery->data;
        $currentPolicy = $favDevices[$mac]['policy'] ?? 'default';
        $newPolicy = $currentPolicy === 'Policy0' ? 'default' : 'Policy0';

        $success = $this->keenetic->setPolicyDevice($mac, $newPolicy);

        // Формируем кнопки заново с обновлёнными статусами
        $buttons = [];

        foreach ($favDevices as $macKey => $device) {
            $policy = $macKey === $mac ? $newPolicy : $device['policy'];
            $emoji = $policy === 'Policy0' ? '🟢' : '⚪';
            $buttons[] = [
                new InlineKeyboardButton(
                    text: "{$device['name']} ($emoji)",
                    callbackData: "{$macKey}"
                )
            ];
        }

        $this->tg->editMessageReplyMarkup(
            chatId: $chatId,
            messageId: $messageId,
            markup: new InlineKeyboardMarkup($buttons)
        );

        $alert = $success
            ? "Политика для устройства $mac изменена на $newPolicy"
            : "Не удалось изменить политику";

        $this->tg->answerCallbackQuery(
            callbackQueryId: $callbackQuery->id,
            text: $alert,
            showAlert: true
        );
    }

    /**
     *  Обрабатывает обычное сообщение пользователя.
     *
     *  Если есть предыдущее сообщение бота для этого chat_id, удаляет его.
     *  Затем отправляет новое сообщение (с inline-кнопками или текст "Вызови /start")
     *  и сохраняет message_id в storage для дальнейшего удаления.
     * @param object $message
     * @param array $favDevices
     * @return void
     */
    private function handleMessage(object $message, array $favDevices): void
    {
        $chatId = $message->chat->id;
        $messageText = $message->text;
        $storage = $this->tg->loadStorage();
        $lastMessageId = $storage['users'][$chatId]['last_message_id'] ?? null;

        if ($lastMessageId) {
            $this->tg->deleteMessage($chatId, $lastMessageId);
        }

        if ($messageText === '/start') {
            $buttons = [];

            foreach ($favDevices as $mac => $device) {
                $emoji = $device['policy'] === 'Policy0' ? '🟢' : '⚪';
                $buttons[] = [
                    new InlineKeyboardButton(
                        text: "{$device['name']} ($emoji)",
                        callbackData: "{$mac}"
                    )
                ];
            }

            $newMessageId = $this->tg->sendMessage(
                chatId: $chatId,
                text: 'Выберите устройство:',
                replyMarkup: new InlineKeyboardMarkup($buttons)
            );
            $this->tg->updateStorage($chatId, ['last_message_id' => $newMessageId]);
        } else {
            $newMessageId = $this->tg->sendMessage(
                chatId: $chatId,
                text: 'Вызови /start'
            );
            $this->tg->updateStorage($chatId, ['last_message_id' => $newMessageId]);
        }
    }
}