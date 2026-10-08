<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('app:create-user {--name= : Nombre de la persona} {--email= : Email con el que entra} {--password= : Contraseña (si no se indica, se pregunta)}')]
#[Description('Create the sixth-sense user, or reset their password if the email already exists')]
class CreateUser extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->option('email') ?? text('Email', required: true);
        $existingUser = User::where('email', $email)->first();

        $name = $this->option('name') ?? $existingUser->name ?? text('Nombre', required: true);
        $password = $this->option('password') ?? password('Contraseña', required: true, hint: 'Mínimo 8 caracteres.');

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $password],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email', 'max:255'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => $password],
        );

        $this->components->info($user->wasRecentlyCreated
            ? "Usuario {$user->email} creado."
            : "Usuario {$user->email} actualizado.");

        return self::SUCCESS;
    }
}
