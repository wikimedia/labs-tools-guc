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

## History

* 2008: Luxo published GUC tool on Toolserver at [toolserver.org/~luxo/contributions/contributions.php](http://toolserver.org/~luxo/contributions/contributions.php) ([Internet Archive](https://web.archive.org/web/20080601140738/http://toolserver.org/~luxo/contributions/contributions.php))
* 2010: Krinkle published MoreContributions tool on Toolserver at [toolserver.org/~krinkle/MoreContributions/](https://toolserver.org/~krinkle/MoreContributions/) ([Screenshot](https://github.com/Krinkle/toolserver-misc#morecontributions), [Source code](https://github.com/Krinkle/toolserver-misc#morecontributions), [Internet Archive](https://web.archive.org/web/20110225023253/https://toolserver.org/~krinkle/MoreContributions/input.php)).
* 2014: GUC rewritten by Luxo for Toolforge, and migrated from SVN (Toolserver Fisheye) to Git (Wikimedia Gerrit) at <https://gerrit.wikimedia.org/g/labs/tools/guc/>.
* 2014: Krinkle added as maintainer.
* 2014: Add "Chronological" and "Wildcard" features from MoreContributions to GUC. [T70358](https://phabricator.wikimedia.org/T70358), [T66499](https://phabricator.wikimedia.org/T66499)
* 2017: Add "Replag" API integration. [T170024](https://phabricator.wikimedia.org/T170024)
* 2017: Add localisation to translate the tool, powered by the [Intuition library](https://gerrit.wikimedia.org/g/labs/tools/intuition). [T151657](https://phabricator.wikimedia.org/T151657)
* 2018: Faster responses through better connection reuse. [T186436](https://phabricator.wikimedia.org/T186436)
* 2018: Apply `comment` table migration. [T208461](https://phabricator.wikimedia.org/T208461)
* 2019: Apply `actor` table migration. [T224440](https://phabricator.wikimedia.org/T224440)
* 2019: Faster responses by adopting WMCS-specific table views: `comment_revision`, `actor_recentchanges`, and `actor_revision`.
* 2019: Faster responses by leveraging CentralAuth metadata. [T195515](https://phabricator.wikimedia.org/T195515)
* 2019: Promote chronological "By date" mode as default. [T193896](https://phabricator.wikimedia.org/T193896)