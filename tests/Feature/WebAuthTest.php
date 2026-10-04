<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
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

        $response->assertOk()->assertJsonStructure(['token', 'expires_in']);
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
}
