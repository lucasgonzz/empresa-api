<?php

namespace Tests\Browser\Helpers;


class ToastHelper
{
    
	static function check_toast($browser, $text) {

		$browser->waitFor('.v-toast__text');
		$browser->assertSeeIn('.v-toast__text', $text);

		dump("Toast OK ($text)");
	}
}
