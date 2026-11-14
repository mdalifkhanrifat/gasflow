<?php

/**
 * GasFlow's own layering rules (docs/project-structure.md, design 04):
 * business rules live in actions, services and models, never in the HTTP
 * layer, so web, API, jobs and commands share them.
 */
arch('actions do not depend on the http layer')
    ->expect('App\Actions')
    ->not->toUse(['App\Http', 'Illuminate\Http\Request']);

arch('services do not depend on the http layer')
    ->expect('App\Services')
    ->not->toUse(['App\Http', 'Illuminate\Http\Request']);

arch('models do not depend on the http layer')
    ->expect('App\Models')
    ->not->toUse(['App\Http', 'Illuminate\Http\Request']);

arch('controllers leave database work to actions and models')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\ConnectionInterface']);

// One rule per namespace: Pest does not check an array of targets one by one.
foreach (['App\Services\Lifecycle', 'App\Services\RequestContext'] as $crossCutting) {
    arch("{$crossCutting} never depends on a business domain")
        ->expect($crossCutting)
        ->not->toUse(['App\Actions', 'App\Services\Licensing']);
}

arch('env() is read only in config files')
    ->expect('env')
    ->not->toBeUsedIn('App');
