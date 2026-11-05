<?php

namespace Tests\Feature;

use Tests\TestCase;

class SaudeTest extends TestCase
{
    public function test_saude_ok_com_banco_e_redis(): void
    {
        $this->getJson('/saude')->assertOk()->assertJson(['status' => 'ok']);
    }

    public function test_redis_indisponivel_retorna_503_explicito_mesmo_com_sessao_no_redis(): void
    {
        config([
            'session.driver' => 'redis',
            'database.redis.default.host' => 'redis-inexistente.invalid',
            'database.redis.default.timeout' => 1,
        ]);

        $this->getJson('/saude')
            ->assertStatus(503)
            ->assertJson(['status' => 'falha', 'checks' => ['banco' => 'ok', 'redis' => 'falha']]);
    }
}
