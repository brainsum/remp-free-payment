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

          $headers = [
            'From' => 'dev+drupalremp@brainsum.com',
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/html; charset=UTF-8',
            'X-Mailer' => 'PHP/' . phpversion()
          ];

          // TODO: Use template for this.

          $body = "<h2>Login credentials:</h2><br/>
          User: <strong>{$user->email}</strong><br/>
          Password: <strong>{$password}</strong><br/>";

          $res = mail($user->email, "New user has been created " , $body, $headers);
        }
    }

}
