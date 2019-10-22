<?php

namespace Crm\FreePaymentModule;

use Crm\ApplicationModule\CrmModule;
use Crm\ApplicationModule\Menu\MenuContainerInterface;
use Crm\ApplicationModule\Menu\MenuItem;
use Crm\ApplicationModule\SeederManager;
use Crm\PaymentsModule\MailConfirmation\ParsedMailLogsRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\PaymentsHistogramFactory;
use Crm\FreePaymentModule\Seeders\FreePaymentGatewaySeeder;
use Kdyby\Translation\Translator;
use Nette\DI\Container;
use Nette\Application\Routers\Route;
use Nette\Application\Routers\RouteList;

class FreePaymentModule extends CrmModule
{
    private $paymentsRepository;

    private $parsedMailLogsRepository;

    private $paymentsHistogramFactory;

    public function __construct(
        Container $container,
        Translator $translator,
        PaymentsRepository $paymentsRepository,
        ParsedMailLogsRepository $parsedMailLogsRepository,
        PaymentsHistogramFactory $paymentsHistogramFactory
    ) {
        parent::__construct($container, $translator);
        $this->paymentsRepository = $paymentsRepository;
        $this->parsedMailLogsRepository = $parsedMailLogsRepository;
        $this->paymentsHistogramFactory = $paymentsHistogramFactory;
    }

    public function registerEventHandlers(\League\Event\Emitter $emitter)
    {
        $emitter->addListener(
            \Crm\UsersModule\Events\UserCreatedEvent::class,
            $this->getInstance(\Crm\FreePaymentModule\Events\UserCreatedEventHandler::class)
        );

        $emitter->addListener(
            \Crm\SubscriptionsModule\Events\NewSubscriptionEvent::class,
            $this->getInstance(\Crm\FreePaymentModule\Events\SubscriptionEventHandler::class)
        );

        $emitter->addListener(
            \Crm\SubscriptionsModule\Events\SubscriptionUpdatedEvent::class,
            $this->getInstance(\Crm\FreePaymentModule\Events\SubscriptionEventHandler::class)
        );
    }

    public function registerSeeders(SeederManager $seederManager)
    {
        $seederManager->addSeeder($this->getInstance(FreePaymentGatewaySeeder::class));
    }

}
