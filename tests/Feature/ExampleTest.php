<?php

test('the application returns a successful response', function () {
    fakeUserdbAreas();

    $this->get('/')->assertSuccessful();
});
