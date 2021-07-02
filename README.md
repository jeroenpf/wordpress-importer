# WordPress Importer

## Set up your local environment

The easiest way to run manual and unit tests for the WordPress Importer is by using [wp-en](https://make.wordpress.org/core/2020/03/03/wp-env-simple-local-environments-for-wordpress/).
The instructions here assume you are using wp-env.

Steps:

1. Install wp-env globally.
2. Run `wp-env start` in the wordpress-importer plugin directory. This will fire up a couple of Docker containers that give you access to a working WordPress instance.


### Run unit tests

To run unit tests some composer dependencies need to be installed. We can run composer and install the dependencies by running th following command inside the substack-importer directory:

`wp-env run composer install`

Unit tests can now be ran with the following command:

`wp-env run phpunit "phpunit --configuration=html/wp-content/plugins/wordpress-importer/phpunit.xml.dist"`
