# WP Rocket Cache Updater
WordPress plugin. Gradually updates the cache of all pages and other post types.
Automatically synchronizes the cache between all instances in AWS Auto Scaling Group. Should be defined environment variables:
- AWS_ACCESS_KEY
- AWS_SECRET_KEY
- AWS_REGION
- ASG_NAME

## Requirements
- PHP 7.2 and above.
- CMS WordPress 5.6 and above.
- Plugin WP-Rocket 3.8 and above.
- Plugin WP Rocket | Disable Cache Clearing.

## Installation
1. Download plugin's source code
```
git clone https://github.com/yuraost/wp-rocket-cache-updater
```
2. Install AWS SDK for PHP
```
composer install
```
3. Install as a regular WordPress plugin