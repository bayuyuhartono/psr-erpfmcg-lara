<?php

namespace App\Console\Commands;

use App\Domain\Masters\Exceptions\SeatsExhausted;
use App\Domain\Masters\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * Creates the first person who can sign in.
 *
 * A fresh installation seeds roles, accounts and layouts but no users, and
 * deliberately so — an administrator with a password shipped in a seeder is an
 * administrator with a password everybody knows, on every installation.
 *
 * So the first account is made here, on the server, by whoever is installing
 * it, with a password they choose.
 */
final class CreateAdministrator extends Command
{
    protected $signature = 'app:create-admin
        {--name= : Their full name}
        {--email= : The address they sign in with}
        {--password= : Only for scripted installs — it will be visible in shell history}
        {--role=Administrator : Which role to give them}';

    protected $description = 'Create a user who can sign in to the admin panel';

    public function handle(): int
    {
        $role = Role::query()->where('name', $this->option('role'))->first();

        if ($role === null) {
            $this->error(sprintf(
                'There is no "%s" role. Run `php artisan db:seed --force` first — it creates the roles.',
                $this->option('role'),
            ));

            return self::FAILURE;
        }

        $name = $this->option('name') ?: text('Full name', required: true);
        $email = $this->option('email') ?: text('Email address', required: true);
        $secret = $this->option('password') ?: password('Password', required: true);

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $secret],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        try {
            $user = User::create([
                'full_name' => $name,
                'email' => $email,
                'password' => $secret,
                'active' => true,
            ]);
        } catch (SeatsExhausted $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $user->roles()->attach($role->getKey());

        $this->info(sprintf('%s can now sign in at /admin as %s.', $name, $role->name));

        return self::SUCCESS;
    }
}
