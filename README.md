# Global user contributions

## Getting started

Requires [Composer](https://getcomposer.org/) and PHP 7.4 or later.

```
composer install
composer serve
```

Then open <http://localhost:4000>.

### Local development

You can use the following patch to stub the database and render some of the response UI locally.

```
# src/App.php
    protected function openDB($host, $dbname = null) {
        return new class() {
            public function prepare(string $query) {
                return new class() {
                    public function bindParam() {
                    }
                    public function execute() {
                    }
                    public function fetchAll() {
                        return [];
                    }
                };
            }
        };
    }
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
mytool@tools-bastion:~$ webservice --backend=kubernetes php8.2 restart
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
