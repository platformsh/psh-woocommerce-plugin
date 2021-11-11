# Platform.sh API Integration for WooCommerce

This is a *(very) incomplete* reference implementation of a WordPress/WooCommerce plugin using the Platform.sh API for managing a limited-access PaaS shop.

> ⚠️ IMPORTANT! ⚠️  Whilst it is likely that this plugin will receive more love over time, it is not actively maintained, nor is it meant to be. As such, it is not to be considered a regularly maintained product and should not be used in production for any reason. 

The (very few) features of this plugin are:

- Provision new Platform.sh projects upon adding new stock to a suitable product
- Summary of provisioned projects, their status, and orders they are linked to
- Provide end user with a summary of the access details upon order completion

## Licence & Contributions

All code in this repository is available under the MIT license. See See [LICENSE.md](https://github.com/platformsh/psh-woocommerce-plugin/blob/master/LICENSE.md) for details.

Pull requests that add generally useful functionality may be accepted.

## Installation

You can install this just like a regular WordPress plugin. If you want to use `composer`, you can refer to the [official documentation](https://getcomposer.org/doc/05-repositories.md#loading-a-package-from-a-vcs-repository) on how to add packages from a VCS source.

## [Requirements](https://github.com/platformsh/psh-woocommerce-plugin/blob/master/comspoer.json)

```json
"require": {
    "php": ">=7.1",
    "wpackagist-plugin/woocommerce": "^5.8",
    "platformsh/client": ">=0.55.0 <2.0",
    "pugx/shortid-php": "^0.7.0"
  },
```

## API Token

This plugin uses the [PHP client for Platform.sh API](https://github.com/platformsh/platformsh-client-php). For this to be initialised correctly, it requires an API Token. You can generate one from your Platform.sh account. The plugin will look for the token in the environment variables: 


```php
$_ENV['PLATFORMSH_TOKEN']
```
