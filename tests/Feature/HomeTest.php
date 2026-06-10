<?php

it('lists areas and AP links on the home page', function () {
    fakeUserdbAreas();

    $this->get('/')
        ->assertSuccessful()
        ->assertSee('Slatina')
        ->assertSee('Brno')
        ->assertSee('Brno-Sever')
        ->assertSee(route('gallery.public', ['area' => 12, 'ap' => 101]))
        ->assertSee(route('gallery.public', ['area' => 13, 'ap' => 202]));
});
