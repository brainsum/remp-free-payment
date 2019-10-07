<?php

namespace Crm\FreePaymentModule\Presenters;

use Crm\SalesFunnelModule\Presenters\SalesFunnelFrontendPresenter;
use Crm\ApplicationModule\Hermes\HermesMessage;
use Crm\ApplicationModule\Presenters\FrontendPresenter;
use Crm\ApplicationModule\Request;
use Crm\PaymentsModule\GatewayFactory;
use Crm\PaymentsModule\PaymentProcessor;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Crm\PaymentsModule\Repository\PaymentsRepository;
use Crm\PaymentsModule\Repository\RecurrentPaymentsRepository;
use Crm\SalesFunnelModule\Events\SalesFunnelEvent;
use Crm\SalesFunnelModule\Repository\SalesFunnelsMetaRepository;
use Crm\SalesFunnelModule\Repository\SalesFunnelsRepository;
use Crm\SalesFunnelModule\Repository\SalesFunnelsStatsRepository;
use Crm\SegmentModule\SegmentFactory;
use Crm\SubscriptionsModule\Repository\ContentAccessRepository;
use Crm\SubscriptionsModule\Repository\SubscriptionTypesRepository;
use Crm\SubscriptionsModule\Subscription\ActualUserSubscription;
use Crm\SubscriptionsModule\Repository\SubscriptionsRepository;
use Crm\SubscriptionsModule\Events\SubscriptionStartsEvent;
use Crm\UsersModule\Auth\Authorizator;
use Crm\UsersModule\Auth\InvalidEmailException;
use Crm\UsersModule\Auth\UserManager;
use Crm\UsersModule\Repository\AddressesRepository;
use Crm\UsersModule\Repository\UsersRepository;
use Nette\Application\BadRequestException;
use Nette\Application\Responses\TextResponse;
use Nette\Application\UI\Form;
use Nette\Database\Table\ActiveRow;
use Nette\Security\AuthenticationException;
use Nette\Utils\DateTime;
use Nette\Utils\Json;
use Nette\Http\Url;
use Tomaj\Hermes\Emitter;

class FreePaymentFrontendPresenter extends SalesFunnelFrontendPresenter
{
    private $salesFunnelsRepository;

    private $subscriptionTypesRepository;

    private $salesFunnelsStatsRepository;

    private $salesFunnelsMetaRepository;

    private $paymentGatewaysRepository;

    private $paymentProcessor;

    private $paymentsRepository;

    private $segmentFactory;

    private $hermesEmitter;

    private $authorizator;

    private $actualUserSubscription;

    private $addressesRepository;

    private $userManager;

    private $gatewayFactory;

    private $recurrentPaymentsRepository;

    private $contentAccessRepository;

    public function __construct(
        SalesFunnelsRepository $salesFunnelsRepository,
        SalesFunnelsStatsRepository $salesFunnelsStatsRepository,
        SalesFunnelsMetaRepository $salesFunnelsMetaRepository,
        SubscriptionTypesRepository $subscriptionTypesRepository,
        PaymentGatewaysRepository $paymentGatewaysRepository,
        PaymentsRepository $paymentsRepository,
        PaymentProcessor $paymentProcessor,
        SegmentFactory $segmentFactory,
        ActualUserSubscription $actualUserSubscription,
        Emitter $hermesEmitter,
        Authorizator $authorizator,
        AddressesRepository $addressesRepository,
        UserManager $userManager,
        GatewayFactory $gatewayFactory,
        RecurrentPaymentsRepository $recurrentPaymentsRepository,
        ContentAccessRepository $contentAccessRepository
    ) {
        parent::__construct(
            $salesFunnelsRepository,
            $salesFunnelsStatsRepository,
            $salesFunnelsMetaRepository,
            $subscriptionTypesRepository,
            $paymentGatewaysRepository,
            $paymentsRepository,
            $paymentProcessor,
            $segmentFactory,
            $actualUserSubscription,
            $hermesEmitter,
            $authorizator,
            $addressesRepository,
            $userManager,
            $gatewayFactory,
            $recurrentPaymentsRepository,
            $contentAccessRepository
        );
        $this->salesFunnelsRepository = $salesFunnelsRepository;
        $this->salesFunnelsStatsRepository = $salesFunnelsStatsRepository;
        $this->salesFunnelsMetaRepository = $salesFunnelsMetaRepository;
        $this->subscriptionTypesRepository = $subscriptionTypesRepository;
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
        $this->paymentProcessor = $paymentProcessor;
        $this->paymentsRepository = $paymentsRepository;
        $this->segmentFactory = $segmentFactory;
        $this->actualUserSubscription = $actualUserSubscription;
        $this->hermesEmitter = $hermesEmitter;
        $this->authorizator = $authorizator;
        $this->addressesRepository = $addressesRepository;
        $this->userManager = $userManager;
        $this->gatewayFactory = $gatewayFactory;
        $this->recurrentPaymentsRepository = $recurrentPaymentsRepository;
        $this->contentAccessRepository = $contentAccessRepository;
    }

    public function startup()
    {
        parent::startup();
        $funnel = filter_input(INPUT_POST, 'funnel_url_key');

        if (!$funnel) {
          $funnel = $this->getHttpRequest()->getUrl()->getQueryParameter('funnel');
        }

        if ($funnel) {
            $salesFunnel = $this->salesFunnelsRepository->findByUrlKey($funnel);
            $gateways = $this->loadGateways($salesFunnel);
            if ($this->action == 'show' && isset($gateways['free'])) {
                $this->redirect(
                    'free',
                    [
                        'funnel' => $funnel,
                        'destination' => $this->getHttpRequest()->getUrl()->getQueryParameter('destination')
                    ]
                );
            }

            if ($this->action == 'free' && !isset($gateways['free'])) {
                $this->redirect(
                    'show',
                    [
                        'funnel' => $funnel,
                        'destination' => $this->getHttpRequest()->getUrl()->getQueryParameter('destination')
                    ]
                );
            }
        }
    }

    public function renderFree($funnel, $referer = null, $values = null, $errors = null) {

        $salesFunnel = $this->salesFunnelsRepository->findByUrlKey($funnel);
        if (!$salesFunnel) {
            throw new BadRequestException('Funnel not found');
        }
        $this->validateFunnel($salesFunnel);

        if (!$referer) {
            $referer = $this->getReferer();
        }

        $gateways = $this->loadGateways($salesFunnel);
        $subscriptionTypes = $this->loadSubscriptionTypes($salesFunnel);
        if ($this->getUser()->id) {
            $subscriptionTypes = $this->filterSubscriptionTypes($subscriptionTypes, $this->getUser()->id);
        }
        if (count($subscriptionTypes) == 0) {
            $this->redirect('limitReached', $salesFunnel->id);
        }

        $addresses = [];
        $body = $salesFunnel->body;

        $loader = new \Twig_Loader_Array([
            'funnel_template' => $body,
        ]);
        $twig = new \Twig_Environment($loader);

        $isLoggedIn = $this->getUser()->isLoggedIn();
        if ((isset($this->request->query['preview']) && $this->request->query['preview'] === 'no-user')
            && $this->getUser()->isAllowed('SalesFunnel:SalesFunnelsAdmin', 'preview')) {
            $isLoggedIn = false;
        }

        if ($isLoggedIn) {
            $addresses = $this->addressesRepository->addresses($this->usersRepository->find($this->getUser()->id), 'print');
        }

        $headEnd = $this->applicationConfig->get('header_block') . "\n\n" . $salesFunnel->head;

        $contentAccess = [];
        foreach ($subscriptionTypes as $index => $subscriptionType) {
            $contentAccess[$subscriptionType['code']] = $this->contentAccessRepository->allForSubscriptionType($subscriptionType)->fetchPairs('name', 'name');

            // casting to array for backwards compatibility and easier Twig access
            $subscriptionTypes[$index] = $subscriptionType->toArray();
        }

        $params = [
            'headEnd' => $headEnd,
            'funnel' => $salesFunnel,
            'isLogged' => $isLoggedIn,
            'gateways' => $gateways,
            'subscriptionTypes' => $subscriptionTypes,
            'contentAccess' => $contentAccess,
            'addresses' => $addresses,
            'meta' => $this->salesFunnelsMetaRepository->all($salesFunnel),
            'jsDomain' => $this->getJavascriptDomain(),
            'actualUserSubscription' => $this->actualUserSubscription,
            'referer' => urlencode($referer),
            'values' => $values ? Json::decode($values, Json::FORCE_ARRAY) : null,
            'errors' => $errors ? Json::decode($errors, Json::FORCE_ARRAY) : null,
            'backLink' => $this->storeRequest(),
        ];

        if ($isLoggedIn) {
            $params['email'] = $this->getUser()->getIdentity()->email;
            $params['user_id'] = $this->getUser()->getIdentity()->getId();
        }
        $template = $twig->render('funnel_template', $params);

        $ua = Request::getUserAgent();
        $this->emitter->emit(new SalesFunnelEvent($salesFunnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_SHOW, $ua));

        $userId = null;
        if ($this->getUser()->isLoggedIn()) {
            $userId = $this->getUser()->getIdentity()->id;
        }
        $browserId = (isset($_COOKIE['browser_id']) ? $_COOKIE['browser_id'] : null);

        $this->hermesEmitter->emit(new HermesMessage('sales-funnel', [
            'type' => 'checkout',
            'user_id' => $userId,
            'browser_id' => $browserId,
            'sales_funnel_id' => $salesFunnel->id,
            'source' => $this->trackingParams(),
        ]));

        $this->sendResponse(new TextResponse($template));
    }

    public function renderSubmit()
    {
        $funnel = $this->salesFunnelsRepository->findByUrlKey(filter_input(INPUT_POST, 'funnel_url_key'));

        $ua = Request::getUserAgent();

        if (!$funnel) {
            throw new BadRequestException('Funnel not found');
        }
        if ($funnel) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_FORM, $ua));
        }

        $this->validateFunnel($funnel);

        $referer = $this->getReferer();

        $address = null;
        $email = filter_input(INPUT_POST, 'email');
        $password = filter_input(INPUT_POST, 'password');
        $needAuth = true;
        if (isset($_POST['auth']) && ($_POST['auth'] == '0' || $_POST['auth'] == 'false')) {
            $needAuth = false;
        }

        $subscriptionTypeCode = filter_input(INPUT_POST, 'subscription_type');
        $subscriptionType = $this->subscriptionTypesRepository->findBy('code', $subscriptionTypeCode);
        $this->validateSubscriptionType($subscriptionType, $funnel);

        $paymentGateway = $this->paymentGatewaysRepository->findByCode(filter_input(INPUT_POST, 'payment_gateway'));
        $this->validateGateway($paymentGateway, $funnel);

        $additionalAmount = 0;
        $additionalType = null;
        if (isset($_POST['additional_amount']) && floatval($_POST['additional_amount']) > 0) {
            $additionalAmount = floatval($_POST['additional_amount']);
            $additionalType = 'single';
            if (isset($_POST['additional_type']) && $_POST['additional_type'] == 'recurrent') {
                $additionalType = 'recurrent';
            }
        }

        $source = $this->getHttpRequest()->getPost('registration_source', 'funnel');

        $user = null;
        try {
            $userError = null;
            $user = $this->user($email, $password, $funnel, $source, $referer, $needAuth);
        } catch (AuthenticationException $e) {
            $userError = Json::encode(['password' => $this->translator->translate("sales_funnel.frontend.invalid_credentials.title")]);
        } catch (InvalidEmailException $e) {
            $userError = Json::encode(['email' => $this->translator->translate("sales_funnel.frontend.invalid_email.title")]);
        }

        if ($userError) {
            $this->redirect(
                'free',
                $funnel->url_key,
                $referer,
                Json::encode([
                    'email' => $email,
                    'payment_gateway' => $paymentGateway->code,
                    'additional_amount' => $additionalAmount,
                    'additional_type' => $additionalType,
                    'subscription_type' => filter_input(INPUT_POST, 'subscription_type'),
                ]),
                $userError
            );
        }

        if (!$this->validateSubscriptionTypeCounts($subscriptionType, $user)) {
            $this->redirect('limitReached', $funnel->id);
        }

        $subscription = $this->createSubscription($subscriptionType, $user);

        $browserId = $_COOKIE['browser_id'] ?? null;
        $metaData = $this->getHttpRequest()->getPost('payment_metadata', []);

        $metaData = array_merge($metaData, $this->trackingParams());
        $metaData['newsletters_subscribe'] = (bool) filter_input(INPUT_POST, 'newsletters_subscribe');
        if ($browserId) {
            $metaData['browser_id'] = $browserId;
        }

        if ($referer) {
            $url = new Url($referer);
            if ($destination = $url->getQueryParameter('destination')) {
                $this->redirect(':SalesFunnel:SalesFunnelFrontend:Success', ['destination' => $destination]);
            }
        }

        $this->flashMessage('Successful subscription');
        $this->redirect(':Subscriptions:Subscriptions:my');
    }

    public function renderSuccess()
    {
        if ($_GET['destination']) {
            $this->template->destination = $_GET['destination'];
        }

        $this->getSession('sales_funnel')->remove();
    }

    private function createSubscription($subscription_type, $user) {
        $subscriptionType = SubscriptionsRepository::TYPE_REGULAR;

        $subscription = $this->subscriptionsRepository->add(
            $subscription_type,
            false,
            $user,
            $subscriptionType,
            null,
            null,
            null,
            null
        );

        if ($subscription->end_time <= new DateTime()) {
            $this->subscriptionsRepository->setExpired($subscription);
        } elseif ($subscription->start_time <= new DateTime()) {
            $this->subscriptionsRepository->update($subscription, ['internal_status' => SubscriptionsRepository::INTERNAL_STATUS_ACTIVE]);
            $this->emitter->emit(new SubscriptionStartsEvent($subscription));
        } else {
            $this->subscriptionsRepository->update($subscription, ['internal_status' => SubscriptionsRepository::INTERNAL_STATUS_BEFORE_START]);
        }

        return $subscription;
    }

    private function loadGateways(ActiveRow $salesFunnel)
    {
        $gateways = [];
        $gatewayRows = $this->salesFunnelsRepository->getSalesFunnelGateways($salesFunnel);
        /** @var ActiveRow $gatewayRow */
        foreach ($gatewayRows as $gatewayRow) {
            $gateways[$gatewayRow->code] = $gatewayRow->toArray();
        }
        return $gateways;
    }

    private function loadSubscriptionTypes(ActiveRow $salesFunnel)
    {
        $subscriptionTypes = [];
        $subscriptionTypesRows = $this->salesFunnelsRepository->getSalesFunnelSubscriptionTypes($salesFunnel);
        /** @var ActiveRow $subscriptionTypesRow */
        foreach ($subscriptionTypesRows as $subscriptionTypesRow) {
            $subscriptionTypes[$subscriptionTypesRow->code] = $subscriptionTypesRow;
        }
        return $subscriptionTypes;
    }

    private function filterSubscriptionTypes(array $subscriptionTypes, int $userId)
    {
        $userSubscriptionsTypesCount = $this->subscriptionsRepository->userSubscriptionTypesCounts($userId, array_column($subscriptionTypes, 'id'));
        foreach ($subscriptionTypes as $code => $subscriptionType) {
            if (!isset($userSubscriptionsTypesCount[$subscriptionType['id']])) {
                continue;
            }

            if ($subscriptionType['limit_per_user'] !== null
                && $subscriptionType['limit_per_user'] <= $userSubscriptionsTypesCount[$subscriptionType['id']]
            ) {
                unset($subscriptionTypes[$code]);
            }
        }
        return $subscriptionTypes;
    }

    private function validateFunnel(ActiveRow $funnel = null)
    {
        if (isset($this->request->query['preview']) && $this->getUser()->isAllowed('SalesFunnel:SalesFunnelsAdmin', 'preview')) {
            return;
        }

        $ua = Request::getUserAgent();

        if (!$funnel) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_NO_ACCESS, $ua));
            $this->redirect('inactive');
        }

        if (!$funnel->is_active) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_NO_ACCESS, $ua));
            $this->redirect('inactive');
            return;
        }

        if ($funnel->start_at && $funnel->start_at > new DateTime()) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_NO_ACCESS, $ua));
            $this->redirect('inactive');
        }

        if ($funnel->end_at && $funnel->end_at < new DateTime()) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_NO_ACCESS, $ua));
            $this->redirect('inactive');
        }

        if ($funnel->only_logged && !$this->getUser()->isLoggedIn()) {
            $this->redirect('signIn', [
                'referer' => isset($_GET['referer']) ? $_GET['referer'] : '',
                'funnel' => isset($_GET['funnel']) ? $_GET['funnel'] : ''
            ]);
        }

        if ($funnel->only_not_logged && $this->getUser()->isLoggedIn()) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_NO_ACCESS, $ua));
            $this->redirect('noAccess', $funnel->id);
        }

        if ($funnel->segment_id) {
            $segmentRow = $funnel->segment;
            if ($segmentRow) {
                $segment = $this->segmentFactory->buildSegment($segmentRow->code);
                $inSegment = $segment->isIn('id', $this->getUser()->id);
                if (!$inSegment) {
                    $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_NO_ACCESS, $ua));
                    $this->redirect('noAccess', $funnel->id);
                }
            }
        }
    }

    private function validateSubscriptionType(ActiveRow $subscriptionType, ActiveRow $funnel)
    {
        $ua = Request::getUserAgent();

        if (!$subscriptionType || !$funnel->related('sales_funnels_subscription_types')->where(['subscription_type_id' => $subscriptionType->id])) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_ERROR, $ua));
            $this->redirect('invalid');
        }

        if (!$subscriptionType->active) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_ERROR, $ua));
            $this->redirect('invalid');
        }

        $subscriptionTypes = $this->loadSubscriptionTypes($funnel);

        if (!isset($subscriptionTypes[$subscriptionType->code])) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_ERROR, $ua));
            $this->redirect('invalid');
        }
    }

    private function validateGateway(ActiveRow $paymentGateway, ActiveRow $funnel)
    {
        $ua = Request::getUserAgent();

        if (!$paymentGateway || !$funnel->related('sales_funnels_payment_gateways')->where(['payment_gateway_id' => $paymentGateway->id])) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_ERROR, $ua));
            $this->redirect('invalid');
        }

        if (!$paymentGateway->visible) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_ERROR, $ua));
            $this->redirect('invalid');
        }

        $gateways = $this->loadGateways($funnel);

        if (!isset($gateways[$paymentGateway->code])) {
            $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_ERROR, $ua));
            $this->redirect('invalid');
        }
    }

    private function user($email, $password, ActiveRow $funnel, $source, $referer, bool $needAuth = true)
    {
        $ua = Request::getUserAgent();

        if ($this->getUser() && $this->getUser()->isLoggedIn()) {
            return $this->userManager->loadUser($this->user);
        }

        $user = $this->userManager->loadUserByEmail($email);
        if ($user) {
            if ($needAuth) {
                $this->getUser()->getAuthenticator()->authenticate(['username' => $email, 'password' => $password]);
            }
        } else {
            $user = $this->userManager->addNewUser($email, true, $source, $referer);
            if (!$user) {
                $this->emitter->emit(new SalesFunnelEvent($funnel, $this->getUser(), SalesFunnelsStatsRepository::TYPE_ERROR, $ua));
                $this->redirect('error');
            }

            $this->getUser()->login(['username' => $user->email, 'alwaysLogin' => true]);
            $this->usersRepository->update($user, [
                'sales_funnel_id' => $funnel->id,
            ]);
        }

        return $user;
    }

    private function validateSubscriptionTypeCounts(ActiveRow $subscriptionType, ActiveRow $user)
    {
        if (!$subscriptionType->limit_per_user) {
            return true;
        }

        $userSubscriptionsTypesCount = $this->subscriptionsRepository->userSubscriptionTypesCounts($user->id, [$subscriptionType->id]);
        if (!isset($userSubscriptionsTypesCount[$subscriptionType->id])) {
            return true;
        }
        if ($subscriptionType->limit_per_user <= $userSubscriptionsTypesCount[$subscriptionType->id]) {
            return false;
        }
        return true;
    }
}
