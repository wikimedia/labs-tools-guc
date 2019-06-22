# Global user contributions

## Getting started

Requires [Composer](https://getcomposer.org/) and PHP 7.2 or later.

```
~/git/guc $ chmod 775 cache/
~/git/guc $ composer install --no-dev
~/git/guc $ php -S localhost:9223 -t public_html/
```

## Toolforge management

See also:
* [Help:Toolforge/Kubernetes#PHP](https://wikitech.wikimedia.org/wiki/Help:Toolforge/Kubernetes#PHP), Wikitech.

### Installation

```
$ ssh tools-login.wmflabs.org

you@tools-bastion$ become my-tool-here

mytool@tools-bastion:~$ git clone … git-guc
mytool@tools-bastion:~$ ln -s git-guc public_html
mytool@tools-bastion:~$ webservice --backend=kubernetes php7.2 start
mytool@tools-bastion:~$ webservice shell

tools.guc@interactive:~$ cd git-guc
tools.guc@interactive:git-guc$ chmod 775 cache/
tools.guc@interactive:git-guc$ composer install --no-dev
```


### Deploy changes

```
$ ssh tools-login.wmflabs.org

you@tools-bastion$ become guc

guc@tools-bastion:~$ webservice shell

tools.guc@interactive:~$ cd git-guc
tools.guc@interactive:git-guc$ git pull
tools.guc@interactive:git-guc$ composer update --no-dev
```
