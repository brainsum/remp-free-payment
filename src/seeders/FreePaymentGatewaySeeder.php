<?php

namespace Crm\FreePaymentModule\Seeders;

use Crm\ApplicationModule\Seeders\ISeeder;
use Crm\PaymentsModule\Repository\PaymentGatewaysRepository;
use Symfony\Component\Console\Output\OutputInterface;

class FreePaymentGatewaySeeder implements ISeeder
{
    private $paymentGatewaysRepository;

    public function __construct(PaymentGatewaysRepository $paymentGatewaysRepository)
    {
        $this->paymentGatewaysRepository = $paymentGatewaysRepository;
    }

    public function seed(OutputInterface $output)
    {
        if (!$this->paymentGatewaysRepository->exists('free')) {
            $this->paymentGatewaysRepository->add(
                'Free',
                'free',
                10,
                true
            );
            $output->writeln('  <comment>* payment gateway <info>Free</info> created</comment>');
        } else {
            $output->writeln('  * payment gateway <info>Free</info> exists');
        }
    }
}
