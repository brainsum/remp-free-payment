REMP Free Payment Module
==

This module adds a free payment gateway and an instant subscription Sales funnel
 action.

Installation
--

Install module via composer:

```
composer require brainsum/remp-free-payment
```

Extend your `app/config/config.neon` file with the following:

```
extensions:
    #...
    - Crm\FreePaymentModule\DI\FreePaymentModuleExtension
    - Nepada\Bridges\PresenterMappingDI\PresenterMappingExtension
#...
application:
    #...
    mapping:
        #...
        'SalesFunnel:SalesFunnelFrontend': Crm\FreePaymentModule\Presenters\FreePaymentFrontendPresenter
```

Seed the database with the free payment gateway:

```
php bin/command.php application:seed
```

Maintainers
--

This module has been creaded and maintained by:

* Levente Besenyei (l-besenyei) - https://github.com/l-besenyei

This module was created and sponsored by Brainsum, a Drupal development company
in Budapest, Hungary.

 * Brainsum - https://www.brainsum.hu/
