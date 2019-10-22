<?php

namespace Crm\FreePaymentModule\Events;

use Crm\ApplicationModule\User\UserData;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Crm\UsersModule\User\ISubscriptionGetter;
use Crm\UsersModule\User\IUserGetter;
use League\Event\AbstractListener;
use League\Event\Emitter;
use League\Event\EventInterface;
use Nette\Security\User;

class SubscriptionEventHandler extends AbstractListener
{
    private $usersRepository;

    private $userData;

    private $subscriptionsRepository;

    private $emitter;

    public function __construct(
        UsersRepository $usersRepository,
        UserData $userData,
        Emitter $emitter,
        SubscriptionsRepository $subscriptionsRepository
    ) {
        $this->usersRepository = $usersRepository;
        $this->userData = $userData;
        $this->subscriptionsRepository = $subscriptionsRepository;
        $this->emitter = $emitter;
    }

    public function handle(EventInterface $event)
    {
        if (!($event instanceof IUserGetter) || !($event instanceof ISubscriptionGetter)) {
            throw new \Exception('cannot handle event, invalid instance received: ' . get_class($event));
        }

        $subscription = $event->getSubscription();

        $userId = $event->getUserId();
        $user = $this->usersRepository->find($event->getUserId());

        $client = new \GuzzleHttp\Client();
        $mailer_host = getenv('MAILER_ADDR');
        $sso_token = getenv('SSO_TOKEN');

        $template_code = "new_subscription";

        if ($event instanceof \Crm\SubscriptionsModule\Events\SubscriptionUpdatedEvent) {
          $template_code = "subscription_updated";
        }

        $url = $mailer_host . '/api/v1/mailers/send-email';
        $body = [
          "mail_template_code" => $template_code,
          "email" => $user->email,
          "params" => [
            'email' => $user->email,
            'start' => $subscription->start_time->format('Y-m-d H:i:s'),
            'end' => $subscription->end_time->format('Y-m-d H:i:s'),
          ]
        ];

        $res = $client->post($url, [
          'headers' => [
            'Content-Type' => 'application/json',
            'Authorization'=> 'Bearer ' . $sso_token,
          ],
          'body' => json_encode($body)
        ]);
    }
}
