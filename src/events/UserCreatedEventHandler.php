<?php

namespace Crm\FreePaymentModule\Events;

use Crm\ApplicationModule\User\UserData;
use Crm\UsersModule\User\IUserGetter;
use Crm\UsersModule\Repository\UsersRepository;
use League\Event\AbstractListener;
use League\Event\EventInterface;
use Nette\Security\User;

class UserCreatedEventHandler extends AbstractListener
{
    private $userData;

    private $usersRepository;

    public function __construct(
        UserData $userData,
        UsersRepository $usersRepository
    ) {
        $this->userData = $userData;
        $this->usersRepository = $usersRepository;
    }

    public function handle(EventInterface $event)
    {
        if ($event->sendEmail()) {
          $password = $event->getOriginalPassword();
          $user = $event->getUser();

          $client = new \GuzzleHttp\Client();
          $mailer_host = getenv('MAILER_ADDR');
          $url = $mailer_host . '/api/v1/mailers/send-email';
          $body = [
            "mail_template_code" => "user_created",
            "email" => $user->email,
            "params" => [
              'email' => $user->email,
              'password' => $password
            ]
          ];

          $res = $client->post($url, [
            'headers' => [
              'Content-Type' => 'application/json',
              'Authorization: Bearer ' . $_COOKIE['n_token'],
            ],
            'body' => json_encode($body)
          ]);
        }
    }

}
