<?php

namespace Guc;

use stdClass;

class ChronologyOutput implements IOutput {
    private $app;
    private $datas;
    private $changes = array();

    public function __construct(App $app, stdClass $datas, array $options = array()) {
        $this->app = $app;
        $this->datas = $datas;
    }

    public function output() {
        foreach ($this->datas as $key => $data) {
            if ($data->error) {
                print '<div class="error"><p>'
                    . '<strong>'
                    . htmlspecialchars($data->wiki->domain ?: $data->wiki->dbname)
                    .'</strong><br/>'
                    . htmlspecialchars($data->error->getMessage())
                    . '</p></div>';
            } else {
                $this->add($data->wiki, $data->contribs);
            }
        }
        $this->sort();
        $prevDate = null;
        $inList = false;
        foreach ($this->changes as $rc) {
            $date = $this->app->formatMwDate($rc->rev_timestamp, 'd M Y');
            // If this is the first result, or this result is the first
            // one on a different date, add a date heading.
            if ($date !== $prevDate) {
                $prevDate = $date;
                if ($inList) {
                    // If we already had results before this,
                    // end the previous list.
                    $inList = false;
                    print '</ul>';
                }
                print $this->makeDateLine($date);
            }
            if (!$inList) {
                // If this is the first result after a new heading,
                // start a list.
                $inList = true;
                print "\n<ul>\n";
            }
            print $this->makeChangeLine($rc);
        }
        // @phan-suppress-next-line PhanRedundantCondition https://github.com/phan/phan/issues/4685
        if ($inList) {
            // Make sure we close the last list
            $inList = false;
            print '</ul>';
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
