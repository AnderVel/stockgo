<?php

namespace Tests\Feature;

use App\Models\Producto;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class StockGoHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::create([
            'name' => 'Admin',
            'username' => 'admin',
            'email' => 'admin@stockgo.test',
            'password' => Hash::make('secret123'),
            'rol' => 'Administrador',
            'bodega_asignada' => 'Central',
        ]);
    }

    private function producto(): Producto
    {
        return Producto::create([
            'codigo_barras' => '7501234567890',
            'nombre' => 'Producto prueba',
            'unidad_medida' => 'pza',
            'precio' => 10.50,
            'stock_fisico' => 20,
            'stock_reservado' => 5,
            'stock_disponible' => 15,
            'estado' => 'ACTIVO',
        ]);
    }

    public function test_editar_producto_no_modifica_stock(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $producto = $this->producto();

        $this->putJson('/api/productos/'.$producto->id_producto, [
            'codigo_barras' => $producto->codigo_barras,
            'nombre' => 'Producto actualizado',
            'unidad_medida' => $producto->unidad_medida,
            'precio' => 12.50,
            'estado' => 'ACTIVO',
        ])->assertOk();

        $producto->refresh();
        $this->assertSame(20, $producto->stock_fisico);
        $this->assertSame(5, $producto->stock_reservado);
        $this->assertSame(15, $producto->stock_disponible);
    }

    public function test_dashboard_entrega_resumen_agregado(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $this->producto();

        $this->getJson('/api/dashboard')
            ->assertOk()
            ->assertJsonPath('kpis.productos', 1)
            ->assertJsonStructure([
                'kpis' => ['productos', 'stock_disponible', 'stock_bajo', 'pedidos', 'clientes'],
                'pedidos_por_estado',
                'movimientos_recientes',
            ]);
    }

    public function test_cuenta_inactiva_no_puede_usar_token_existente(): void
    {
        $user = $this->admin();
        $user->forceFill(['activo' => false])->save();
        Sanctum::actingAs($user, ['api-access']);

        $this->getJson('/api/productos')
            ->assertStatus(403)
            ->assertJsonPath('message', 'La cuenta está inactiva.');
    }

    public function test_login_web_fallido_se_audita(): void
    {
        $this->admin();

        $this->postJson('/api/web/auth/login', [
            'usuario' => 'admin',
            'password' => 'incorrecta',
        ])->assertUnauthorized();

        $this->assertDatabaseHas('auditorias', [
            'accion' => 'LOGIN_WEB_FALLIDO',
            'modulo' => 'AUTH',
        ]);
    }

    public function test_web_puede_configurar_y_confirmar_su_2fa(): void
    {
        $this->admin();

        $setup = $this->postJson('/api/web/auth/2fa/setup', [
            'usuario' => 'admin',
            'password' => 'secret123',
        ])->assertOk()->assertJsonStructure(['secret', 'otpauth_url']);

        $secret = $setup->json('secret');
        $code = app(Google2FA::class)->getCurrentOtp($secret);

        $this->postJson('/api/web/auth/2fa/confirm', [
            'usuario' => 'admin',
            'password' => 'secret123',
            'code' => $code,
        ])->assertOk();

        $this->assertDatabaseHas('users', [
            'username' => 'admin',
            'two_factor_enabled' => true,
        ]);

        $this->postJson('/api/web/auth/login', [
            'usuario' => 'admin',
            'password' => 'secret123',
            'code' => $code,
        ])->assertOk();
    }

    public function test_web_2fa_rechaza_codigo_invalido_y_no_operador(): void
    {
        $this->admin();

        $this->postJson('/api/web/auth/2fa/setup', [
            'usuario' => 'admin',
            'password' => 'secret123',
        ])->assertOk();

        $this->postJson('/api/web/auth/2fa/confirm', [
            'usuario' => 'admin',
            'password' => 'secret123',
            'code' => '000000',
        ])->assertStatus(422);

        User::create([
            'name' => 'Operador',
            'username' => 'operador',
            'email' => 'operador@stockgo.test',
            'password' => Hash::make('secret123'),
            'rol' => 'Operador de Almacén',
            'bodega_asignada' => 'Central',
        ]);

        $this->postJson('/api/web/auth/2fa/setup', [
            'usuario' => 'operador',
            'password' => 'secret123',
        ])->assertStatus(403);
    }

    public function test_listados_conservan_array_android_y_ofrecen_paginacion_optativa(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $this->producto();

        $this->getJson('/api/productos')
            ->assertOk()
            ->assertJsonIsArray();

        $this->getJson('/api/productos?paginate=1&per_page=1')
            ->assertOk()
            ->assertJsonStructure(['data', 'current_page', 'last_page', 'per_page', 'total'])
            ->assertJsonPath('per_page', 1);
    }

    public function test_reset_password_con_token_invalido_devuelve_422(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'email' => 'admin@stockgo.test',
            'token' => 'token-invalido',
            'password' => 'newsecret123',
            'password_confirmation' => 'newsecret123',
        ])->assertStatus(422);
    }
}
