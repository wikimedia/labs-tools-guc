<?php

namespace Guc;

use stdClass;

interface IOutput {

    public function __construct(App $app, stdClass $datas);

    public function output();
}
