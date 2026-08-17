<x-layouts::auth :title="__('Log in')">
    <div class="flex flex-col gap-6">
        {{-- Encabezado del formulario --}}
        <x-auth-header
            :title="__('Log in to your account')"
            :description="__('Enter your email and password below to log in')"
        />

        {{-- Muestra mensajes de sesión, por ejemplo, al recuperar la contraseña --}}
        <x-auth-session-status
            class="text-center"
            :status="session('status')"
        />

        {{-- Formulario de inicio de sesión --}}
        <form
            method="POST"
            action="{{ route('login.store') }}"
            class="flex flex-col gap-6"
        >
            @csrf

            {{-- Correo electrónico --}}
            <flux:input
                name="email"
                :label="__('Email address')"
                :value="old('email')"
                type="email"
                required
                autofocus
                autocomplete="email"
                placeholder="email@example.com"
            />

            {{-- Contraseña --}}
            <div class="relative">
                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autocomplete="current-password"
                    :placeholder="__('Password')"
                    viewable
                />

                {{-- Solo muestra el enlace si existe la ruta de recuperación --}}
                @if (Route::has('password.request'))
                    <flux:link
                        class="absolute top-0 end-0 text-sm"
                        :href="route('password.request')"
                        wire:navigate
                    >
                        {{ __('Forgot your password?') }}
                    </flux:link>
                @endif
            </div>

            {{-- Mantener la sesión iniciada --}}
            <flux:checkbox
                name="remember"
                :label="__('Remember me')"
                :checked="old('remember')"
            />

            {{-- Botón para iniciar sesión --}}
            <div class="flex items-center justify-end">
                <flux:button
                    variant="primary"
                    type="submit"
                    class="w-full"
                    data-test="login-button"
                >
                    {{ __('Log in') }}
                </flux:button>
            </div>
        </form>

        {{--
            El enlace para crear una cuenta solo se renderiza cuando la ruta
            "register" existe. Como deshabilitamos el registro público,
            este bloque no aparecerá ni provocará el error RouteNotFoundException.
        --}}
        @if (Route::has('register'))
            <div class="space-x-1 text-center text-sm text-zinc-600 rtl:space-x-reverse dark:text-zinc-400">
                <span>{{ __('Don\'t have an account?') }}</span>

                <flux:link :href="route('register')" wire:navigate>
                    {{ __('Sign up') }}
                </flux:link>
            </div>
        @endif
    </div>
</x-layouts::auth>