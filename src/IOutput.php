<?php

namespace Guc;

use stdClass;

interface IOutput {

	public function __construct( App $app, stdClass $datas, array $options = [] );

	public function output();
}
