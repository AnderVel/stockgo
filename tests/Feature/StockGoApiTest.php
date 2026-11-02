<?php

namespace Tests\Feature;

use App\Models\Cliente;
use App\Models\Movimiento;
use App\Models\Pedido;
use App\Models\Producto;
use App\Models\Proveedor;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class StockGoApiTest extends TestCase
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

    private function operador(): User
    {
        return User::create([
            'name' => 'Operador',
            'username' => 'operador',
            'email' => 'operador@stockgo.test',
            'password' => Hash::make('secret123'),
            'rol' => 'Operador de Almacén',
            'bodega_asignada' => 'Central',
        ]);
    }

    private function producto(array $overrides = []): Producto
    {
        return Producto::create(array_merge([
            'codigo_barras' => '7501234567890',
            'nombre' => 'Producto prueba',
            'unidad_medida' => 'pza',
            'precio' => 10.50,
            'stock_fisico' => 100,
            'stock_reservado' => 0,
            'stock_disponible' => 100,
            'estado' => 'ACTIVO',
            'ubicacion' => 'Pasillo 1',
        ], $overrides));
    }

    public function test_login_devuelve_setup_token_si_2fa_no_configurado(): void
    {
        $admin = $this->admin();

        $response = $this->postJson('/api/auth/login', [
            'usuario' => 'admin',
            'password' => 'secret123',
        ]);

        $response->assertOk()
            ->assertJsonStructure(['status', 'two_factor_setup_required', 'setup_token']);
    }

    public function test_login_rechaza_credenciales_invalidas(): void
    {
        $this->admin();

        $this->postJson('/api/auth/login', [
            'usuario' => 'admin',
            'password' => 'incorrecta',
        ])->assertStatus(401);
    }

    public function test_me_requiere_token_api_access(): void
    {
        $admin = $this->admin();
        Sanctum::actingAs($admin, ['api-access']);

        $this->getJson('/api/auth/me')->assertOk()
            ->assertJsonPath('user.username', 'admin');
    }

    public function test_operador_no_puede_crear_productos(): void
    {
        Sanctum::actingAs($this->operador(), ['api-access']);

        $this->postJson('/api/productos', [
            'codigo_barras' => '123',
            'nombre' => 'X',
            'unidad_medida' => 'pza',
            'precio' => 1,
            'stock_fisico' => 1,
            'stock_reservado' => 0,
            'estado' => 'ACTIVO',
        ])->assertStatus(403);
    }

    public function test_entrada_salida_ajuste_mantienen_invariante(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $p = $this->producto();

        $this->postJson('/api/movimientos/entrada', [
            'id_producto' => $p->id_producto,
            'cantidad' => 10,
        ])->assertStatus(201);

        $p->refresh();
        $this->assertSame(110, $p->stock_fisico);
        $this->assertSame(110, $p->stock_disponible);

        $this->postJson('/api/movimientos/salida', [
            'id_producto' => $p->id_producto,
            'cantidad' => 30,
        ])->assertStatus(201);

        $p->refresh();
        $this->assertSame(80, $p->stock_fisico);
        $this->assertSame(80, $p->stock_disponible);

        $this->postJson('/api/movimientos/ajuste', [
            'id_producto' => $p->id_producto,
            'cantidad' => -20,
            'motivo' => 'Merma',
        ])->assertStatus(201);

        $p->refresh();
        $this->assertSame(60, $p->stock_fisico);
        $this->assertGreaterThanOrEqual(0, $p->stock_disponible);
        $this->assertSame(3, Movimiento::count());
    }

    public function test_salida_sin_stock_falla_422(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $p = $this->producto(['stock_fisico' => 5, 'stock_disponible' => 5]);

        $this->postJson('/api/movimientos/salida', [
            'id_producto' => $p->id_producto,
            'cantidad' => 10,
        ])->assertStatus(422);
    }

    public function test_movimiento_picking_no_confia_en_usuario_id_del_body(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $p = $this->producto();

        $this->postJson('/api/inventario/movimiento', [
            'codigo_barras' => $p->codigo_barras,
            'tipo_movimiento' => 'picking',
            'cantidad' => 5,
            'usuario_id' => 99999,
        ])->assertOk()->assertJsonPath('status', 'success');

        $p->refresh();
        $this->assertSame(95, $p->stock_fisico);

        $mov = Movimiento::latest('id_movimiento')->first();
        $this->assertNotNull($mov->usuario_id);
    }

    public function test_proveedor_con_movimientos_no_se_puede_eliminar(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $proveedor = Proveedor::create(['nombre' => 'Prov', 'estado' => 'ACTIVO']);
        $p = $this->producto();

        $this->postJson('/api/movimientos/entrada', [
            'id_producto' => $p->id_producto,
            'cantidad' => 1,
            'id_proveedor' => $proveedor->id_proveedor,
        ])->assertStatus(201);

        $this->deleteJson('/api/proveedores/'.$proveedor->id_proveedor)
            ->assertStatus(409);
    }

    public function test_flujo_completo_de_pedido(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $cliente = Cliente::create(['nombre' => 'Cliente 1', 'estado' => 'ACTIVO']);
        $p = $this->producto();

        $response = $this->postJson('/api/pedidos', [
            'id_cliente' => $cliente->id_cliente,
            'fecha_pedido' => now()->toDateString(),
            'detalles' => [
                ['id_producto' => $p->id_producto, 'cantidad' => 10],
            ],
        ]);

        $response->assertStatus(201);
        $pedidoId = $response->json('id_pedido');

        $p->refresh();
        $this->assertSame(10, $p->stock_reservado);
        $this->assertSame(90, $p->stock_disponible);

        $this->postJson("/api/pedidos/$pedidoId/entregar")->assertStatus(409);

        $this->postJson("/api/pedidos/$pedidoId/surtir")->assertOk();
        $p->refresh();
        $this->assertSame(90, $p->stock_fisico);
        $this->assertSame(0, $p->stock_reservado);

        $this->postJson("/api/pedidos/$pedidoId/entregar")->assertOk();
        $this->postJson("/api/pedidos/$pedidoId/recibir")->assertOk();
    }

    public function test_producto_movimiento_entrada_persistente(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $p = $this->producto(['stock_fisico' => 50, 'stock_disponible' => 50]);

        $ok = $this->postJson('/api/productos/'.$p->id_producto.'/movimiento', [
            'tipo' => 'entrada',
            'cantidad' => 10,
            'pedido_id' => null,
        ]);

        $ok->assertOk()->assertJsonPath('status', 'success');
        $p->refresh();
        $this->assertSame(60, $p->stock_fisico);
        $this->assertSame(60, $p->stock_disponible);

        $mov = Movimiento::where('id_producto', $p->id_producto)->latest('id_movimiento')->first();
        $this->assertNotNull($mov);
        $this->assertSame('ENTRADA', $mov->tipo);
        $this->assertSame(auth()->id(), $mov->usuario_id ?? auth()->id());
    }

    public function test_producto_movimiento_salida_insuficiente_sin_restar(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $p = $this->producto(['stock_fisico' => 5, 'stock_disponible' => 5]);

        $this->postJson('/api/productos/'.$p->id_producto.'/movimiento', [
            'tipo' => 'salida',
            'cantidad' => 10,
        ])->assertStatus(422);

        $p->refresh();
        $this->assertSame(5, $p->stock_fisico);
    }

    public function test_cancelar_pedido_libera_reserva(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $cliente = Cliente::create(['nombre' => 'Cliente 2', 'estado' => 'ACTIVO']);
        $p = $this->producto();

        $response = $this->postJson('/api/pedidos', [
            'id_cliente' => $cliente->id_cliente,
            'fecha_pedido' => now()->toDateString(),
            'detalles' => [
                ['id_producto' => $p->id_producto, 'cantidad' => 25],
            ],
        ])->assertStatus(201);

        $pedidoId = $response->json('id_pedido');

        $this->postJson("/api/pedidos/$pedidoId/cancelar")->assertOk();

        $p->refresh();
        $this->assertSame(0, $p->stock_reservado);
        $this->assertSame(100, $p->stock_disponible);
    }

    public function test_pedido_fallido_no_deja_cambios_en_stock(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $cliente = Cliente::create(['nombre' => 'Cliente 2', 'estado' => 'ACTIVO']);
        $p = $this->producto(['stock_fisico' => 10, 'stock_disponible' => 10]);

        // Intentar crear pedido con cantidad > stock_disponible
        $response = $this->postJson('/api/pedidos', [
            'id_cliente' => $cliente->id_cliente,
            'fecha_pedido' => now()->toDateString(),
            'detalles' => [
                ['id_producto' => $p->id_producto, 'cantidad' => 15], // más que stock_disponible
            ],
        ]);

        $response->assertStatus(422);

        // Verificar que NO hubo cambios en stock
        $p->refresh();
        $this->assertSame(10, $p->stock_fisico);
        $this->assertSame(0, $p->stock_reservado);
        $this->assertSame(10, $p->stock_disponible);

        // Verificar que NO se creó el pedido ni sus detalles
        $this->assertDatabaseMissing('pedidos', ['id_cliente' => $cliente->id_cliente]);
        $this->assertDatabaseMissing('detalle_pedidos', ['id_producto' => $p->id_producto]);
        $this->assertDatabaseMissing('movimientos', ['id_producto' => $p->id_producto]);
    }

    public function test_actualizar_pedido_fallido_no_deja_cambios(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $cliente = Cliente::create(['nombre' => 'Cliente 4', 'estado' => 'ACTIVO']);
        $p = $this->producto(['stock_fisico' => 100, 'stock_disponible' => 100, 'stock_reservado' => 0]);

        // Crear pedido válido
        $response = $this->postJson('/api/pedidos', [
            'id_cliente' => $cliente->id_cliente,
            'fecha_pedido' => now()->toDateString(),
            'detalles' => [
                ['id_producto' => $p->id_producto, 'cantidad' => 10],
            ],
        ])->assertStatus(201);

        $pedidoId = $response->json('id_pedido');
        $p->refresh();
        $this->assertSame(10, $p->stock_reservado);
        $this->assertSame(90, $p->stock_disponible);

        // Intentar actualizar con cantidad que excede stock_disponible (90)
        // diferencia = 101 - 10 = 91 > stock_disponible (90)
        $response = $this->putJson("/api/pedidos/$pedidoId", [
            'id_cliente' => $cliente->id_cliente,
            'fecha_pedido' => now()->toDateString(),
            'detalles' => [
                ['id_producto' => $p->id_producto, 'cantidad' => 101],
            ],
        ]);

        $response->assertStatus(422);

        // Verificar que el stock quedó igual (rollback atómico)
        $p->refresh();
        $this->assertSame(10, $p->stock_reservado);
        $this->assertSame(90, $p->stock_disponible);

        // Verificar que los detalles del pedido no cambiaron
        $pedido = Pedido::find($pedidoId);
        $this->assertSame(10, $pedido->detalles->first()->cantidad);
    }

    public function test_picking_android_no_descuenta_reservas_ajenas(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access']);
        $p = $this->producto(['stock_fisico' => 100, 'stock_disponible' => 100, 'stock_reservado' => 0]);

        // Crear reserva manual (simulando pedido previo)
        $p->stock_reservado = 20;
        $p->stock_disponible = 80;
        $p->save();

        // Picking SIN pedido_id - solo debe validar stock_disponible, NO tocar stock_reservado
        $response = $this->postJson('/api/inventario/movimiento', [
            'codigo_barras' => $p->codigo_barras,
            'tipo_movimiento' => 'picking',
            'cantidad' => 10,
        ])->assertOk()->assertJsonPath('status', 'success');

        $p->refresh();
        $this->assertSame(90, $p->stock_fisico);      // 100 - 10
        $this->assertSame(20, $p->stock_reservado);   // SIN CAMBIO (sin pedido_id)
        $this->assertSame(70, $p->stock_disponible);  // 90 - 20

        // Picking CON pedido_id que NO existe - no debe tocar stock_reservado
        $response = $this->postJson('/api/inventario/movimiento', [
            'codigo_barras' => $p->codigo_barras,
            'tipo_movimiento' => 'picking',
            'cantidad' => 5,
            'id_pedido' => 99999, // pedido inexistente
        ])->assertOk()->assertJsonPath('status', 'success');

        $p->refresh();
        $this->assertSame(85, $p->stock_fisico);      // 90 - 5
        $this->assertSame(20, $p->stock_reservado);   // SIN CAMBIO (pedido inexistente)
        $this->assertSame(65, $p->stock_disponible);  // 85 - 20

        // Crear un pedido real para probar picking CON pedido válido
        $cliente = Cliente::create(['nombre' => 'Cliente Test', 'estado' => 'ACTIVO']);
        $pedido = Pedido::create([
            'id_cliente' => 1, // se ignorará, se usa el cliente creado
            'fecha_pedido' => now()->toDateString(),
            'estado' => 'PENDIENTE',
            'total' => 0,
        ]);
        $pedido->id_cliente = $cliente->id_cliente;
        $pedido->save();

        // Agregar el producto al pedido con cantidad reservada suficiente (20)
        $pedido->detalles()->create([
            'id_producto' => $p->id_producto,
            'cantidad' => 20,
            'precio_unitario' => $p->precio,
            'subtotal' => $p->precio * 20,
        ]);

        // Picking CON pedido_id válido Y reserva suficiente - debe reducir stock_reservado
        $response = $this->postJson('/api/inventario/movimiento', [
            'codigo_barras' => $p->codigo_barras,
            'tipo_movimiento' => 'picking',
            'cantidad' => 5,
            'id_pedido' => $pedido->id_pedido,
        ])->assertOk()->assertJsonPath('status', 'success');

        $p->refresh();
        $this->assertSame(80, $p->stock_fisico);      // 85 - 5
        $this->assertSame(15, $p->stock_reservado);   // 20 - 5
        $this->assertSame(65, $p->stock_disponible);  // 80 - 15
    }

    public function test_entrada_producto_exitosa(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access', 'inventory.receive']);
        $p = $this->producto(['stock_fisico' => 50, 'stock_reservado' => 10, 'stock_disponible' => 40]);

        $response = $this->postJson("/api/productos/{$p->id_producto}/entrada", [
            'cantidad' => 20,
            'motivo' => 'Recepción de proveedor',
            'id_proveedor' => null,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Entrada registrada')
            ->assertJsonPath('producto.id_producto', $p->id_producto)
            ->assertJsonPath('producto.stock_fisico', 70)
            ->assertJsonPath('producto.stock_reservado', 10)
            ->assertJsonPath('producto.stock_disponible', 60);

        $p->refresh();
        $this->assertSame(70, $p->stock_fisico);
        $this->assertSame(10, $p->stock_reservado);
        $this->assertSame(60, $p->stock_disponible);

        // Verificar que se creó el movimiento
        $this->assertDatabaseHas('movimientos', [
            'id_producto' => $p->id_producto,
            'tipo' => 'ENTRADA',
            'cantidad' => 20,
        ]);
    }

    public function test_entrada_producto_con_proveedor(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access', 'inventory.receive']);
        $proveedor = Proveedor::create(['nombre' => 'Proveedor Test', 'estado' => 'ACTIVO']);
        $p = $this->producto(['stock_fisico' => 50, 'stock_reservado' => 0, 'stock_disponible' => 50]);

        $response = $this->postJson("/api/productos/{$p->id_producto}/entrada", [
            'cantidad' => 25,
            'motivo' => 'Compra a proveedor',
            'id_proveedor' => $proveedor->id_proveedor,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Entrada registrada')
            ->assertJsonPath('producto.id_producto', $p->id_producto)
            ->assertJsonPath('producto.stock_fisico', 75)
            ->assertJsonPath('producto.stock_reservado', 0)
            ->assertJsonPath('producto.stock_disponible', 75);

        $p->refresh();
        $this->assertSame(75, $p->stock_fisico);
        $this->assertSame(0, $p->stock_reservado);
        $this->assertSame(75, $p->stock_disponible);

        // Verificar que se guardó el proveedor en el movimiento
        $this->assertDatabaseHas('movimientos', [
            'id_producto' => $p->id_producto,
            'tipo' => 'ENTRADA',
            'cantidad' => 25,
            'id_proveedor' => $proveedor->id_proveedor,
        ]);
    }

    public function test_entrada_producto_cantidad_invalida(): void
    {
        Sanctum::actingAs($this->admin(), ['api-access', 'inventory.receive']);
        $p = $this->producto();

        // Cantidad cero
        $response = $this->postJson("/api/productos/{$p->id_producto}/entrada", [
            'cantidad' => 0,
        ]);
        $response->assertStatus(422);

        // Cantidad negativa
        $response = $this->postJson("/api/productos/{$p->id_producto}/entrada", [
            'cantidad' => -5,
        ]);
        $response->assertStatus(422);

        // Cantidad nula
        $response = $this->postJson("/api/productos/{$p->id_producto}/entrada", [
            'cantidad' => null,
        ]);
        $response->assertStatus(422);
    }

    public function test_entrada_producto_sin_permiso(): void
    {
        // Usuario sin rol que tenga permiso inventory.receive
        $usuario = User::create([
            'name' => 'Usuario Sin Permiso',
            'username' => 'sinpermiso',
            'email' => 'sinpermiso@test.com',
            'password' => Hash::make('secret123'),
            'rol' => 'Cliente', // Rol que no existe en config/permissions.php
            'bodega_asignada' => 'Central',
        ]);

        Sanctum::actingAs($usuario, ['api-access']);

        $p = $this->producto();

        $response = $this->postJson("/api/productos/{$p->id_producto}/entrada", [
            'cantidad' => 10,
        ]);

        $response->assertStatus(403);
    }

    public function test_entrada_producto_operador_con_permiso(): void
    {
        $operador = User::create([
            'name' => 'Operador',
            'username' => 'operador',
            'email' => 'operador@test.com',
            'password' => Hash::make('secret123'),
            'rol' => 'Operador de Almacén',
            'bodega_asignada' => 'Central',
        ]);

        Sanctum::actingAs($operador, ['api-access', 'inventory.receive', 'inventory.view']);

        $p = $this->producto(['stock_fisico' => 50, 'stock_reservado' => 0, 'stock_disponible' => 50]);

        $response = $this->postJson("/api/productos/{$p->id_producto}/entrada", [
            'cantidad' => 15,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('status', 'success')
            ->assertJsonPath('message', 'Entrada registrada')
            ->assertJsonPath('producto.id_producto', $p->id_producto)
            ->assertJsonPath('producto.stock_fisico', 65)
            ->assertJsonPath('producto.stock_reservado', 0)
            ->assertJsonPath('producto.stock_disponible', 65);
    }
}
