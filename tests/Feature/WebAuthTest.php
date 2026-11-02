<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use PDOException;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class WebAuthTest extends TestCase
{
    use RefreshDatabase;

    private function adminCon2Fa(): array
    {
        $secret = app(Google2FA::class)->generateSecretKey();

        $user = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@stockgo.test',
            'password' => Hash::make('secret123'),
            'rol' => 'Administrador',
            'bodega_asignada' => 'Central',
        ]);

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
        ])->save();

        return [$user, $secret];
    }

    private function regularCon2Fa(): array
    {
        $secret = app(Google2FA::class)->generateSecretKey();

        $user = User::create([
            'name' => 'Admin Regular',
            'username' => 'regular',
            'email' => 'regular@stockgo.test',
            'password' => Hash::make('secret123'),
            'rol' => 'Administrador Regular',
            'bodega_asignada' => 'Central',
        ]);

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_secret' => $secret,
        ])->save();

        return [$user, $secret];
    }

    public function test_login_web_sin_2fa_configurado_falla(): void
    {
        User::create([
            'name' => 'Op',
            'username' => 'op',
            'email' => 'op@test.com',
            'password' => Hash::make('secret123'),
            'rol' => 'Operador de Almacén',
        ]);

        $this->postJson('/api/web/auth/login', [
            'usuario' => 'op',
            'password' => 'secret123',
        ])->assertStatus(403);
    }

    public function test_login_web_completo_emite_jwt_y_refresh(): void
    {
        [$user, $secret] = $this->adminCon2Fa();

        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $response = $this->postJson('/api/web/auth/login', [
            'usuario' => 'admin',
            'password' => 'secret123',
            'code' => $code,
        ]);

        $response->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonStructure([
                'status',
                'token',
                'token_type',
                'expires_in',
                'user' => [
                    'id',
                    'username',
                    'rol',
                    'bodega_asignada',
                    'activo',
                    'two_factor_enabled',
                ],
            ]);
        $response->assertCookie('stockgo_refresh');
        $this->assertFalse($response->headers->has('refresh_token'));
        $this->assertArrayNotHasKey('refresh_token', $response->json());

        $token = $response->json('token');

        $this->getJson('/api/web/auth/me', ['Authorization' => "Bearer $token"])
            ->assertOk()->assertJsonPath('user.username', 'admin');

        $refreshCookie = $response->headers->getCookies()[0];
        $this->assertTrue($refreshCookie->isHttpOnly());

        $refreshResponse = $this->call(
            'POST', '/api/web/auth/refresh', [],
            ['stockgo_refresh' => $refreshCookie->getValue()],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
        );

        $refreshResponse->assertOk()->assertJsonStructure(['token', 'expires_in']);
        $this->assertArrayNotHasKey('refresh_token', $refreshResponse->json());

        // Reutilizar el mismo refresh token (ya rotado) debe fallar
        $this->call(
            'POST', '/api/web/auth/refresh', [],
            ['stockgo_refresh' => $refreshCookie->getValue()],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
        )->assertStatus(401);
    }

    public function test_login_web_rechaza_totp_invalido(): void
    {
        $this->adminCon2Fa();

        $this->postJson('/api/web/auth/login', [
            'usuario' => 'admin',
            'password' => 'secret123',
            'code' => '000000',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'El código de autenticación no es válido.');
    }

    public function test_web_me_rechaza_jwt_invalido(): void
    {
        $this->getJson('/api/web/auth/me', [
            'Authorization' => 'Bearer token-invalido',
        ])->assertUnauthorized();
    }

    public function test_refresh_purga_conexion_tras_perder_conexion_con_neon(): void
    {
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new PDOException('server closed the connection unexpectedly', 7));
        DB::shouldReceive('purge')->once()->withNoArgs();
        Log::shouldReceive('error')->once();

        $this->call(
            'POST',
            '/api/web/auth/refresh',
            [],
            ['stockgo_refresh' => 'refresh-token-de-prueba'],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
        )
            ->assertStatus(503)
            ->assertHeader('Retry-After', '2')
            ->assertJsonPath('message', 'El servicio de sesión no está disponible temporalmente. Intenta de nuevo.');
    }

    public function test_ruta_web_rechaza_sin_token(): void
    {
        $this->getJson('/api/web/productos')->assertStatus(401);
    }

    public function test_endpoint_usuarios_y_auditorias_admin(): void
    {
        [$user, $secret] = $this->adminCon2Fa();
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $token = $this->postJson('/api/web/auth/login', [
            'usuario' => 'admin',
            'password' => 'secret123',
            'code' => $code,
        ])->json('token');

        $this->getJson('/api/web/usuarios', ['Authorization' => "Bearer $token"])->assertOk();
        $this->getJson('/api/web/auditorias', ['Authorization' => "Bearer $token"])->assertOk();
    }

    public function test_operador_no_puede_ver_usuarios_ni_auditoria_web(): void
    {
        $op = User::create([
            'name' => 'Op', 'username' => 'op', 'email' => 'op@t.com',
            'password' => Hash::make('secret123'), 'rol' => 'Operador de Almacén',
        ]);

        Sanctum::actingAs($op, ['api-access']);

        $this->getJson('/api/usuarios')->assertStatus(403);
        $this->getJson('/api/auditorias')->assertStatus(403);
    }

    public function test_usuario_desactivado_no_puede_renovar_refresh_token(): void
    {
        [$user, $secret] = $this->adminCon2Fa();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $response = $this->postJson('/api/web/auth/login', [
            'usuario' => 'admin',
            'password' => 'secret123',
            'code' => $code,
        ])->assertOk();

        $refreshCookie = $response->headers->getCookies()[0];

        // Desactivar el usuario
        $user->forceFill(['activo' => false])->save();

        // Intentar renovar el refresh token debe fallar
        $this->call(
            'POST', '/api/web/auth/refresh', [],
            ['stockgo_refresh' => $refreshCookie->getValue()],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
        )->assertStatus(403)
            ->assertJsonPath('message', 'La cuenta está inactiva o no tiene acceso al Web Admin.');
    }

    public function test_refresh_token_reutilizado_no_emite_nueva_sesion(): void
    {
        [$user, $secret] = $this->adminCon2Fa();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $response = $this->postJson('/api/web/auth/login', [
            'usuario' => 'admin',
            'password' => 'secret123',
            'code' => $code,
        ])->assertOk();

        $refreshCookie = $response->headers->getCookies()[0];

        // Primera renovación - debe funcionar
        $refreshResponse1 = $this->call(
            'POST', '/api/web/auth/refresh', [],
            ['stockgo_refresh' => $refreshCookie->getValue()],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
        );
        $refreshResponse1->assertOk();

        // Obtener la nueva cookie de refresh
        $newRefreshCookie = $refreshResponse1->headers->getCookies()[0];

        // Segunda renovación con la cookie NUEVA - debe funcionar
        $refreshResponse2 = $this->call(
            'POST', '/api/web/auth/refresh', [],
            ['stockgo_refresh' => $newRefreshCookie->getValue()],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
        );
        $refreshResponse2->assertOk();

        // Intentar reutilizar la PRIMERA cookie (ya revocada) - debe fallar
        $this->call(
            'POST', '/api/web/auth/refresh', [],
            ['stockgo_refresh' => $refreshCookie->getValue()],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
        )->assertStatus(401);
    }

    public function test_cambio_contraseña_invalida_refresh_tokens_anteriores(): void
    {
        [$user, $secret] = $this->adminCon2Fa();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        // Login web para obtener refresh token
        $response = $this->postJson('/api/web/auth/login', [
            'usuario' => 'admin',
            'password' => 'secret123',
            'code' => $code,
        ])->assertOk();

        $refreshCookie = $response->headers->getCookies()[0];

        // Cambiar contraseña usando Sanctum actingAs (simula usuario autenticado con api-access)
        Sanctum::actingAs($user, ['api-access']);

        $this->postJson('/api/auth/change-password', [
            'current_password' => 'secret123',
            'password' => 'NuevaClaveSegura2024!',
            'password_confirmation' => 'NuevaClaveSegura2024!',
        ])->assertOk();

        // Intentar usar el refresh token web anterior - debe fallar
        $this->call(
            'POST', '/api/web/auth/refresh', [],
            ['stockgo_refresh' => $refreshCookie->getValue()],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
        )->assertStatus(401);
    }

    public function test_administrador_regular_puede_loguearse_web_admin(): void
    {
        [$user, $secret] = $this->regularCon2Fa();

        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $response = $this->postJson('/api/web/auth/login', [
            'usuario' => 'regular',
            'password' => 'secret123',
            'code' => $code,
        ]);

        $response->assertOk()->assertJsonStructure(['token', 'expires_in', 'user']);
        $response->assertCookie('stockgo_refresh');
        $this->assertEquals('Administrador Regular', $response->json('user.rol'));
    }

    public function test_administrador_regular_puede_renovar_refresh_token(): void
    {
        [$user, $secret] = $this->regularCon2Fa();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $response = $this->postJson('/api/web/auth/login', [
            'usuario' => 'regular',
            'password' => 'secret123',
            'code' => $code,
        ])->assertOk();

        $refreshCookie = $response->headers->getCookies()[0];

        $refreshResponse = $this->call(
            'POST', '/api/web/auth/refresh', [],
            ['stockgo_refresh' => $refreshCookie->getValue()],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
        );

        $refreshResponse->assertOk()->assertJsonStructure(['token', 'expires_in', 'user']);
        $this->assertEquals('Administrador Regular', $refreshResponse->json('user.rol'));
    }

    public function test_administrador_regular_accede_endpoints_lectura_web(): void
    {
        [$user, $secret] = $this->regularCon2Fa();
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $token = $this->postJson('/api/web/auth/login', [
            'usuario' => 'regular',
            'password' => 'secret123',
            'code' => $code,
        ])->json('token');

        $headers = ['Authorization' => "Bearer $token"];

        // Endpoints de lectura permitidos para Administrador Regular
        $this->getJson('/api/web/productos', $headers)->assertOk();
        $this->getJson('/api/web/dashboard', $headers)->assertOk();
        $this->getJson('/api/web/movimientos', $headers)->assertOk();
        $this->getJson('/api/web/pedidos', $headers)->assertOk();
        $this->getJson('/api/web/clientes', $headers)->assertOk();
        $this->getJson('/api/web/proveedores', $headers)->assertOk();
        $this->getJson('/api/web/auditorias', $headers)->assertOk();
    }

    public function test_administrador_regular_no_accede_endpoints_escritura_web(): void
    {
        [$user, $secret] = $this->regularCon2Fa();
        $code = app(Google2FA::class)->getCurrentOtp($secret);
        $token = $this->postJson('/api/web/auth/login', [
            'usuario' => 'regular',
            'password' => 'secret123',
            'code' => $code,
        ])->json('token');

        $headers = ['Authorization' => "Bearer $token"];

        // Endpoints de escritura NO permitidos para Administrador Regular
        $this->postJson('/api/web/productos', [
            'codigo_barras' => '7501234567890',
            'nombre' => 'Producto',
            'unidad_medida' => 'pza',
            'precio' => 10.50,
            'stock_fisico' => 100,
            'estado' => 'ACTIVO',
        ], $headers)->assertStatus(403);

        $this->postJson('/api/web/usuarios', [
            'name' => 'Nuevo',
            'username' => 'nuevo',
            'email' => 'nuevo@test.com',
            'password' => 'ClaveSegura12345',
            'rol' => 'Operador de Almacén',
            'bodega_asignada' => 'Central',
        ], $headers)->assertStatus(403);

        $this->postJson('/api/web/movimientos/entrada', [
            'id_producto' => 1,
            'cantidad' => 10,
        ], $headers)->assertStatus(403);
    }

    public function test_cambio_rol_invalida_tokens_anteriores(): void
    {
        // 1. Login como Administrador Regular
        [$regular, $secret] = $this->regularCon2Fa();
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $loginResponse = $this->postJson('/api/web/auth/login', [
            'usuario' => 'regular',
            'password' => 'secret123',
            'code' => $code,
        ])->assertOk();

        $token = $loginResponse->json('token');
        $refreshCookie = $loginResponse->headers->getCookies()[0];

        // 2. Admin cambia el rol del usuario a Operador de Almacén
        $admin = User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@stockgo.test',
            'password' => Hash::make('secret123'),
            'rol' => 'Administrador',
            'bodega_asignada' => 'Central',
        ]);
        Sanctum::actingAs($admin, ['api-access', 'users.update', 'users.view']);

        $this->putJson("/api/usuarios/{$regular->id}", [
            'name' => 'Admin Regular',
            'rol' => 'Operador de Almacén',
            'activo' => true,
            'bodega_asignada' => 'Central',
        ])->assertOk()->assertJsonPath('rol', 'Operador de Almacén');

        // 3. El access token ANTIGUO ya no debe permitir acceso a endpoints web
        // (JWT no se puede revocar sin lista negra, pero el middleware CheckPermission
        //  debe rechazar basándose en el rol ACTUAL de la BD)
        $this->getJson('/api/web/usuarios', ['Authorization' => "Bearer $token"])->assertStatus(403);

        // 4. El refresh token ANTIGUO debe ser rechazado al renovar (401 porque fue revocado)
        $this->call(
            'POST', '/api/web/auth/refresh', [],
            ['stockgo_refresh' => $refreshCookie->getValue()],
            [],
            ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json']
        )->assertStatus(401);
    }
}
