<?php

namespace Williamcruzme\Fcm;

use Carbon\Carbon;
use Closure;
use Illuminate\Notifications\Notification;
use Williamcruzme\Fcm\Exceptions\CouldNotSendNotification;
use Illuminate\Support\Facades\Log;
class FcmChannel
{
    /**
     * @var int
     */
    const MAX_TOKEN_PER_REQUEST = 500;

    /**
     * @var \Closure|null
     */
    protected static $beforeSendingCallback;

    /**
     * Send the given notification.
     *
     * @param mixed $notifiable
     * @param \Illuminate\Notifications\Notification $notification
     *
     * @return void
     * @throws \Williamcruzme\Fcm\Exceptions\CouldNotSendNotification
     * @throws \Kreait\Firebase\Exception\FirebaseException
     */
    public function send($notifiable, Notification $notification)
    {
        // Get device token
        $token = $notifiable->routeNotificationFor('fcm', $notification);
        if (empty($token)) {
            return;
        }

        $token = is_array($token) && count($token) === 1 ? $token[0] : $token;

        // Get the message from the notification
        $message = $notification->toFcm($notifiable);
        if (! $message instanceof FcmMessage) {
            throw CouldNotSendNotification::invalidMessage();
        }

        // Apply before sending callback
        if ($callback = static::$beforeSendingCallback) {
            $message = $callback($message, $notification, $notifiable);
        }
        $statistic = (object)$message->toArray()['notification'];

        $push = resolve(config('fcm.statistic_class'))::firstOrCreate([
            'for' => $statistic->for,
            'title' => $statistic->title,
            'text' => $statistic->body,
            'date' => Carbon::now(),
            'auto' => $statistic->auto,
            'status' => true
        ]);

        $sended = is_array($token)? count($token): 1;
        $failed = 0;

        try {
            // Send notification
            if (is_array($token)) {
                $partialTokens = array_chunk($token, self::MAX_TOKEN_PER_REQUEST, false);
                foreach ($partialTokens as $tokens) {
                    $report = $this->messaging()->sendMulticast($message, $tokens);
                    $failed += count($report->unknownTokens()) ?? 0;
                    $unknownTokens = $report->unknownTokens();
                    if (! empty($unknownTokens)) {
                        $notifiable->devices()->whereIn('token', $unknownTokens)->get()->each->delete();
                    }
                }

                $push->update([
                    'for' => $statistic->for,
                    'title' => $statistic->title,
                    'text' => $statistic->body,
                    'date' => Carbon::now(),
                    'auto' => $statistic->auto,
                    'status' => $sended - $failed >= 1 ? true : false,
                    'sending' => $push->sending + $sended,
                    'success' => $push->success + ($sended - $failed),
                    'failed' => $push->failed + $failed
                ]);

            } else {
                $message->token($token);
                if($this->messaging()->send($message)){
                    $status = true;
                } else {
                    $status = false;
                }
                $push->update([
                    'for' => $statistic->for,
                    'title' => $statistic->title,
                    'text' => $statistic->body,
                    'date' => Carbon::now(),
                    'auto' => $statistic->auto,
                    'status' => $status,
                    'sending' => $sended,
                    'success' => $sended - $failed,
                    'failed' => $failed
                ]);
            }


        } catch (\Exception $exception) {
            Log::error($exception);
//            $notifiable->devices()->get()->each->delete();
        }
    }


    /**
     * @return \Kreait\Firebase\Messaging
     */
    protected function messaging()
    {
        return app('firebase.messaging');
    }

    /**
     * Set the callback to be run before sending message.
     *
     * @param  \Closure  $callback
     * @return void
     */
    public static function beforeSending(Closure $callback)
    {
        static::$beforeSendingCallback = $callback;
    }
}
