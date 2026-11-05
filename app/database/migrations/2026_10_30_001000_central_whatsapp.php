<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Central de WhatsApp (RF23): números oficiais, conversas e mensagens (entrada, saída pelo sistema e ecos do celular). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('whatsapp_numeros', function (Blueprint $table) {
            $table->id();
            $table->string('nome', 80);
            $table->string('telefone', 20)->unique(); // dígitos, sem +55
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->restrictOnDelete(); // null = número central (triagem)
            $table->string('provedor', 20); // simulado | meta
            $table->string('modo_conexao', 20)->default('api'); // api | coexistencia (informativo)
            $table->string('phone_number_id', 64)->nullable()->unique(); // id do número na Meta
            $table->text('credenciais')->nullable(); // JSON cifrado (token, app secret, verify token); nunca exibido
            $table->jsonb('modelos')->default('[]'); // modelos aprovados: [{nome, idioma}]
            $table->unsignedSmallInteger('minutos_alerta')->default(15);
            $table->boolean('ativo')->default(true);
            $table->foreignId('criado_por')->constrained('users')->restrictOnDelete();
            $table->timestampsTz();
        });
        DB::statement("ALTER TABLE whatsapp_numeros ADD CONSTRAINT whatsapp_numeros_valores CHECK (provedor IN ('simulado','meta') AND modo_conexao IN ('api','coexistencia')
            AND minutos_alerta BETWEEN 1 AND 1440 AND telefone ~ '^[0-9]{10,15}$' AND (provedor <> 'meta' OR phone_number_id IS NOT NULL))");

        Schema::create('whatsapp_conversas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('numero_id')->constrained('whatsapp_numeros')->restrictOnDelete();
            $table->foreignId('unidade_id')->nullable()->constrained('unidades')->restrictOnDelete(); // null = em triagem
            $table->string('contato_telefone', 20); // normalizado (sem 55)
            $table->string('contato_wa_id', 20); // como o provedor identifica o contato (destino dos envios)
            $table->string('contato_nome', 120)->nullable();
            $table->foreignId('cliente_id')->nullable()->constrained('clientes')->restrictOnDelete();
            $table->foreignId('ordem_id')->nullable()->constrained('ordens')->restrictOnDelete();
            $table->string('situacao', 20)->default('nova');
            $table->foreignId('responsavel_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('ultima_mensagem_em')->nullable();
            $table->timestampTz('ultima_entrada_em')->nullable(); // última mensagem do cliente: janela de 24 h
            $table->timestampTz('aguardando_desde')->nullable(); // 1ª mensagem do cliente ainda sem resposta
            $table->timestampTz('resolvida_em')->nullable();
            $table->text('motivo_resolucao')->nullable();
            $table->timestampsTz();
            $table->unique(['numero_id', 'contato_telefone']); // uma conversa por (número, contato); resolvida é reaberta
            $table->index(['unidade_id', 'situacao']);
            $table->index('aguardando_desde');
        });
        DB::statement("ALTER TABLE whatsapp_conversas ADD CONSTRAINT whatsapp_conversas_valores CHECK (situacao IN ('nova','em_atendimento','aguardando_cliente','resolvida')
            AND (situacao <> 'resolvida' OR (motivo_resolucao IS NOT NULL AND length(trim(motivo_resolucao)) > 0 AND resolvida_em IS NOT NULL)))");

        Schema::create('whatsapp_mensagens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversa_id')->constrained('whatsapp_conversas')->restrictOnDelete();
            $table->foreignId('numero_id')->constrained('whatsapp_numeros')->restrictOnDelete();
            $table->string('direcao', 10); // entrada | saida
            $table->string('origem', 10); // cliente | sistema | celular
            $table->boolean('historico')->default(false); // importada do histórico do app (não gera alerta)
            $table->string('tipo', 20); // texto | imagem | audio | documento | localizacao | modelo
            $table->text('texto')->nullable();
            $table->string('modelo', 120)->nullable();
            $table->string('midia_id_provedor', 200)->nullable();
            $table->string('midia_caminho', 255)->nullable(); // disco privado
            $table->string('midia_mime', 100)->nullable();
            $table->string('midia_nome', 200)->nullable();
            $table->unsignedBigInteger('midia_tamanho')->nullable();
            $table->string('midia_status', 20)->nullable(); // pendente | baixada | rejeitada | falhou
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('id_provedor', 200)->nullable();
            $table->string('status', 20); // recebida | enviando | enviada | entregue | lida | falhou
            $table->unsignedSmallInteger('tentativas')->default(0);
            $table->text('ultimo_erro')->nullable();
            $table->foreignId('autor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('ocorrida_em'); // horário original (provedor) ou do registro
            $table->timestampTz('enviada_em')->nullable();
            $table->timestampTz('entregue_em')->nullable();
            $table->timestampTz('lida_em')->nullable();
            $table->timestampTz('aguardava_desde')->nullable(); // espera que esta resposta encerrou (restaurada se falhar)
            $table->unsignedInteger('tempo_resposta_segundos')->nullable();
            $table->timestampsTz();
            $table->index(['conversa_id', 'ocorrida_em']);
        });
        DB::statement('CREATE UNIQUE INDEX whatsapp_mensagens_id_provedor ON whatsapp_mensagens (numero_id, id_provedor) WHERE id_provedor IS NOT NULL');
        DB::statement("ALTER TABLE whatsapp_mensagens ADD CONSTRAINT whatsapp_mensagens_valores CHECK (
            direcao IN ('entrada','saida') AND origem IN ('cliente','sistema','celular')
            AND tipo IN ('texto','imagem','audio','documento','localizacao','modelo')
            AND status IN ('recebida','enviando','enviada','entregue','lida','falhou')
            AND (midia_status IS NULL OR midia_status IN ('pendente','baixada','rejeitada','falhou'))
            AND ((direcao = 'entrada' AND origem = 'cliente' AND status = 'recebida' AND autor_id IS NULL)
              OR (direcao = 'saida' AND origem = 'sistema' AND status <> 'recebida')
              OR (direcao = 'saida' AND origem = 'celular' AND autor_id IS NULL AND status <> 'recebida')))");
    }

    public function down(): void
    {
        Schema::dropIfExists('whatsapp_mensagens');
        Schema::dropIfExists('whatsapp_conversas');
        Schema::dropIfExists('whatsapp_numeros');
    }
};
