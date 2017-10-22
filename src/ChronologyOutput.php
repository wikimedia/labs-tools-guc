<?php
/**
 * @author Timo Tijhof
 * @copyright 2016
 * @file
 */

namespace Guc;

use stdClass;

class ChronologyOutput implements IOutput {
    private $app;
    private $datas;
    private $changes = array();
    private $prevDate;

    public function __construct(App $app, stdClass $datas, array $options = array()) {
        $this->app = $app;
        $this->datas = $datas;
    }

    public function output() {
        foreach ($this->datas as $key => $data) {
            if ($data->error) {
                print '<div class="error">'
                    . '<strong>'
                    . htmlspecialchars($data->wiki->domain ?: $data->wiki->dbname)
                    .'</strong><br/>'
                    . htmlspecialchars($data->error->getMessage())
                    . '</div>';
            } else {
                $this->add($data->wiki, $data->contribs);
            }
        }
        $this->sort();
        $inList = false;
        foreach ($this->changes as $rc) {
            $date = $this->app->formatMwDate($rc->rev_timestamp, 'd M Y');
            if ($date !== $this->prevDate) {
                $this->prevDate = $date;
                if ($inList) {
                    $inList = false;
                    print '</ul>';
                }
                print $this->makeDateLine($date);
            }
            if (!$inList) {
                $inList = true;
                print "\n<ul>\n";
            }
            print $this->makeChangeLine($rc);
        }
    }

    private function add(Wiki $wiki, Contribs $contribs) {
        if ($contribs->hasContribs()) {
            foreach ($contribs->getContribs() as $rc) {
                $rc->guc_wiki = $wiki;
                $this->changes[] = $rc;
            }
        }
    }

    private function sort() {
        usort($this->changes, function ($a, $b) {
            if ($a->rev_timestamp == $b->rev_timestamp) {
                return 0;
            }
            // DESC
            return $a->rev_timestamp < $b->rev_timestamp ? 1 : -1;
        });
    }

    private function makeDateLine($date) {
        return '<h2>' . htmlspecialchars($date) . '</h2>' . "\n";
    }

    private function makeChangeLine(stdClass $rc) {
        $chunks = Contribs::formatChange($this->app, $rc->guc_wiki, $rc);
        return '<li>' . join('&nbsp;', $chunks) . '</li>';
    }
}
