<?php

it('redirects guests from the root to the login page', function () {
    $response = $this->get('/');

    $response->assertRedirect('/login');
});
