<?php

/**
 * Pest's standard rule sets:
 * - php: no debugging leftovers (dd, dump, var_dump, die, ...)
 * - security: no eval, weak hashes or unsafe randomness
 * - laravel: Laravel's naming and placement conventions (commands end in
 *   "Command", controllers in "Controller", models extend Model, ...)
 *
 * App\Jobs\Middleware holds job middleware, as the Laravel docs suggest; it is
 * not a job, so the "jobs are queueable" rule does not apply there.
 */
arch('php')->preset()->php();

arch('security')->preset()->security();

arch('laravel')->preset()->laravel()->ignoring('App\Jobs\Middleware');
