<?php

namespace App\Livewire;

use App\Integracoes\Placa\ConsultaPlaca;
use App\Integracoes\Placa\ConsultaPlacaIndisponivel;
use App\Models\Cliente;
use App\Models\Unidade;
use App\Models\Veiculo;
use App\Services\Entradas;
use App\Support\Endereco;
use App\Support\Fuso;
use App\Support\Normaliza;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class NovaEntrada extends Component
{
    use WithFileUploads;

    public ?int $unidade_id = null;

    public ?int $cliente_id = null;

    public string $busca = ''; // 06/11/2026: achar cliente já cadastrado pelo nome (cadastro da rede; tolerante a erro de digitação)

    public string $nome = '';

    public string $telefone = '';

    public string $documento = '';

    public string $placa = '';

    public string $motivo = '';

    public string $modelo = '';

    public string $marca = '';

    public string $ano = '';

    public string $cor = '';

    public string $chassi = '';

    public ?string $avisoPlaca = null; // resultado da consulta externa sem dados

    public ?string $origemVeiculo = null; // 'sistema' | 'consulta' (06/11/2026: dados puxados pela placa)

    public string $quilometragem = '';

    public string $observacoes = '';

    public array $fotos = [];

    public bool $contingencia = false;

    public string $horario_original = '';

    public string $contingencia_nota = '';

    public bool $confirmar_transferencia = false;

    // Entrada sem veículo/placa (peças avulsas, ex.: jogo de rodas) e endereço opcional do cliente.
    public bool $sem_veiculo = false;

    /** Entrada de um orçamento sem entrada aprovado (?orcamento=NUMERO): a OS recebe o mesmo número e os itens. */
    #[\Livewire\Attributes\Locked]
    public ?int $avulsoId = null;

    #[\Livewire\Attributes\Locked]
    public ?string $avulsoNumero = null;

    public string $item_descricao = '';

    public array $endereco = ['cep' => '', 'logradouro' => '', 'numero' => '', 'complemento' => '', 'bairro' => '', 'cidade' => '', 'uf' => ''];

    public function mount(): void
    {
        $this->authorize('registrar_entrada');
        $user = auth()->user();
        $ativas = Unidade::visiveisPara($user)->where('ativa', true)->pluck('id');
        // Com uma só unidade o campo não aparece na tela: já vem escolhida (com visão global ficava vazio e a validação barrava sem mostrar erro).
        $this->unidade_id = $user->visaoGlobal() ? ($ativas->count() === 1 ? $ativas->first() : null) : $user->unidade_id;
        // Pré-preenchimento vindo de outra tela (ex.: Central de WhatsApp); o cadastro existente é sugerido pela busca normal.
        $this->telefone = Normaliza::telefone((string) request()->query('telefone', ''));
        $this->nome = mb_substr(trim((string) request()->query('nome', '')), 0, 150);
        if ($numero = request()->query('orcamento')) {
            $a = \App\Models\OrcamentoAvulso::visiveisPara($user)->with('cliente')->where('numero', (string) $numero)->where('status', 'aprovado')->first();
            if ($a) {
                [$this->avulsoId, $this->avulsoNumero, $this->unidade_id] = [$a->id, $a->numero, $a->unidade_id];
                [$this->cliente_id, $this->telefone, $this->nome] = [$a->cliente_id, (string) $a->cliente->telefone, $a->cliente->nome];
                $this->motivo = "Orçamento {$a->numero} aprovado pelo cliente.";
                if ($a->placa) {
                    $this->placa = $a->placa;
                    $this->updatedPlaca();
                }
            }
        }
    }

    protected function rules(): array
    {
        $user = auth()->user();

        return [
            'unidade_id' => ['required', 'integer', Rule::in(Unidade::visiveisPara($user)->where('ativa', true)->pluck('id')->all())],
            'cliente_id' => ['nullable', 'integer', Rule::exists('clientes', 'id')->whereNull('mesclado_em_id')],
            'nome' => ['required', 'string', 'min:2', 'max:150'],
            'telefone' => ['required', 'regex:/^\d{10,11}$/'],
            'documento' => ['nullable', 'regex:/^(\d{11}|\d{14})$/'],
            'sem_veiculo' => ['boolean'],
            'placa' => ['exclude_if:sem_veiculo,true', 'required', 'regex:'.Normaliza::REGEX_PLACA],
            'item_descricao' => ['exclude_unless:sem_veiculo,true', 'required', 'string', 'min:3', 'max:200'],
            'motivo' => ['required', 'string', 'min:3', 'max:2000'],
            'modelo' => ['exclude_if:sem_veiculo,true', 'nullable', 'string', 'max:80'],
            'marca' => ['exclude_if:sem_veiculo,true', 'nullable', 'string', 'max:40'],
            'ano' => ['exclude_if:sem_veiculo,true', 'nullable', 'integer', 'min:1950', 'max:'.(now()->year + 1)],
            'cor' => ['exclude_if:sem_veiculo,true', 'nullable', 'string', 'max:30'],
            'chassi' => ['exclude_if:sem_veiculo,true', 'nullable', 'regex:'.Normaliza::REGEX_CHASSI, Rule::unique('veiculos', 'chassi')->where(fn ($q) => $q->where('placa', '<>', $this->placa))],
            'quilometragem' => ['nullable', 'integer', 'min:0', 'max:2000000'],
            'observacoes' => ['nullable', 'string', 'max:2000'],
            'fotos' => ['array', 'max:5'],
            'fotos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
            'contingencia' => ['boolean'],
            'confirmar_transferencia' => ['boolean'],
            'endereco' => ['array'],
            'endereco.cep' => ['nullable', 'regex:/^\d{8}$/'],
            'endereco.logradouro' => ['nullable', 'string', 'max:150'],
            'endereco.numero' => ['nullable', 'string', 'max:20'],
            'endereco.complemento' => ['nullable', 'string', 'max:80'],
            'endereco.bairro' => ['nullable', 'string', 'max:80'],
            'endereco.cidade' => ['nullable', 'string', 'max:80'],
            'endereco.uf' => ['nullable', Rule::in(Endereco::UFS)],
            'horario_original' => ['exclude_unless:contingencia,true', 'required', 'date'],
            'contingencia_nota' => ['exclude_unless:contingencia,true', 'required', 'string', 'min:5', 'max:500'],
        ];
    }

    protected function validationAttributes(): array
    {
        return ['unidade_id' => 'unidade', 'nome' => 'nome do cliente', 'motivo' => 'motivo da visita', 'horario_original' => 'horário original',
            'contingencia_nota' => 'observação da contingência', 'fotos.*' => 'foto', 'quilometragem' => 'quilometragem',
            'item_descricao' => 'descrição do item', 'endereco.cep' => 'CEP', 'endereco.uf' => 'UF', 'endereco.logradouro' => 'logradouro', 'endereco.numero' => 'número',
            'endereco.complemento' => 'complemento', 'endereco.bairro' => 'bairro', 'endereco.cidade' => 'cidade'];
    }

    protected function messages(): array
    {
        return [
            'telefone.regex' => 'Informe o telefone com DDD (10 ou 11 dígitos).',
            'documento.regex' => 'CPF (11 dígitos) ou CNPJ (14 dígitos).',
            'placa.regex' => 'Placa inválida. Use o formato ABC1234 ou ABC1D23.',
            'chassi.regex' => 'Chassi deve ter 17 letras e números (sem I, O ou Q).',
            'chassi.unique' => 'Este chassi já está cadastrado em outro veículo.',
            'unidade_id.in' => 'Escolha uma unidade ativa à qual você tem acesso.',
            'endereco.cep.regex' => 'Informe o CEP com 8 dígitos.',
            'endereco.uf.in' => 'Escolha uma UF válida.',
        ];
    }

    public function updatedTelefone(): void
    {
        $this->telefone = Normaliza::telefone($this->telefone);
        $this->cliente_id = null;
    }

    /**
     * Ao digitar: placa já cadastrada → puxa veículo e dono do sistema (se nenhum cliente foi escolhido/digitado).
     * A consulta externa (lenta, de terceiro) só roda pelo botão "Buscar dados do veículo" (consultarPlaca).
     */
    public function updatedPlaca(): void
    {
        $this->avisoPlaca = null;
        $this->placa = Normaliza::placa($this->placa);
        if ($this->origemVeiculo) { // placa mudou: descarta o que foi puxado da placa anterior
            $this->marca = $this->modelo = $this->ano = $this->cor = $this->chassi = '';
        }
        $this->origemVeiculo = null;
        if (! preg_match(Normaliza::REGEX_PLACA, $this->placa)) {
            return;
        }
        $veiculo = Veiculo::with('cliente')->where('placa', $this->placa)->first();
        if ($veiculo) {
            $this->preencherVeiculo($veiculo->only('marca', 'modelo', 'ano', 'cor'));
            $this->chassi = $this->chassi ?: (string) $veiculo->chassi;
            $this->origemVeiculo = 'sistema';
            $dono = $veiculo->cliente;
            if (! $this->cliente_id && $this->telefone === '' && $dono && $dono->mesclado_em_id === null) {
                $this->cliente_id = $dono->id;
                $this->nome = $dono->nome;
                $this->telefone = $dono->telefone;
            }

        }
    }

    /** Botão "Buscar dados do veículo": consulta externa com indicador de carregamento na tela. Nunca bloqueia a entrada. */
    public function consultarPlaca(): void
    {
        $this->updatedPlaca();
        if (! preg_match(Normaliza::REGEX_PLACA, $this->placa)) {
            $this->addError('placa', 'Digite a placa completa (ex.: ABC1D23).');

            return;
        }
        if ($this->origemVeiculo === 'sistema') {
            return; // já cadastrada: dados do sistema
        }
        if (! RateLimiter::attempt('consulta-placa:'.auth()->id(), 20, fn () => true)) {
            $this->avisoPlaca = 'Muitas consultas seguidas. Aguarde um minuto ou preencha os dados à mão.';

            return;
        }
        try {
            $dados = app(ConsultaPlaca::class)->consultar($this->placa);
        } catch (\Throwable $e) {
            report($e);
            $dados = null;
        }
        if ($dados) {
            $this->preencherVeiculo($dados);
            $this->origemVeiculo = 'consulta';
        } else {
            $this->avisoPlaca = 'Não encontramos dados desta placa agora. Preencha marca, modelo, ano e cor à mão.';
        }
    }

    private function preencherVeiculo(array $d): void
    {
        foreach (['marca' => 40, 'modelo' => 80, 'cor' => 30] as $k => $max) {
            if ($this->{$k} === '' && ! empty($d[$k])) {
                $this->{$k} = mb_substr((string) $d[$k], 0, $max);
            }
        }
        if ($this->ano === '' && ! empty($d['ano'])) {
            $this->ano = (string) (int) $d['ano'];
        }
    }

    public function usarCliente(int $id): void
    {
        // Só cadastros encontrados pelo telefone, placa ou nome digitados (sem enumeração por id).
        $cliente = Cliente::ativos()->with('veiculos')->whereKey($id)->where(fn ($q) => $q->where('telefone', $this->telefone)
            ->orWhereHas('veiculos', fn ($v) => $v->where('placa', $this->placa))
            ->when($this->buscaValida(), fn ($q, $b) => $q->orWhere(fn ($q) => $q->parecido($b))))->firstOrFail();
        $this->cliente_id = $cliente->id;
        $this->nome = $cliente->nome;
        $this->telefone = $cliente->telefone;
        $this->documento = auth()->user()->can('ver_contato_cliente') ? (string) $cliente->documento : '';
        $this->busca = '';
        // Um só veículo e placa vazia: já preenche (dá para trocar se veio com outro carro).
        if (! $this->sem_veiculo && $this->placa === '' && $cliente->veiculos->count() === 1) {
            $this->placa = $cliente->veiculos->first()->placa;
            $this->preencherVeiculo($cliente->veiculos->first()->only('marca', 'modelo', 'ano', 'cor'));
            $this->origemVeiculo = 'sistema';
        }
    }

    private function buscaValida(): ?string
    {
        $b = trim($this->busca);

        return mb_strlen($b) >= 3 ? mb_substr($b, 0, 80) : null;
    }

    public function salvar(Entradas $entradas)
    {
        $this->telefone = Normaliza::telefone($this->telefone);
        $this->placa = $this->sem_veiculo ? '' : Normaliza::placa($this->placa);
        $this->documento = (string) preg_replace('/\D/', '', $this->documento);
        $this->chassi = Normaliza::chassi($this->chassi);
        $this->endereco = array_map(fn ($v) => (string) $v, Endereco::normalizar($this->endereco));
        if (! auth()->user()->can('ver_contato_cliente')) {
            $this->endereco = array_fill_keys(array_keys($this->endereco), '');
        }
        $dados = $this->validate();
        $dados['sem_veiculo'] = $this->sem_veiculo;
        $dados['orcamento_avulso_id'] = $this->avulsoId;
        $dados['ano'] = $this->sem_veiculo || $this->ano === '' ? null : (int) $this->ano;
        $dados['endereco'] = $this->endereco;

        if ($this->contingencia) {
            $original = CarbonImmutable::parse($this->horario_original, Fuso::LOCAL)->utc();
            if ($original->isFuture() || $original->lt(now()->subDays(7))) {
                $this->addError('horario_original', 'O horário original deve estar nos últimos 7 dias e não pode ser futuro.');

                return null;
            }
            $dados['horario_original'] = $original;
        }
        $dados['quilometragem'] = $this->quilometragem === '' ? null : (int) $this->quilometragem;

        $ordem = $entradas->registrar(auth()->user(), $dados, $this->fotos);
        session()->flash('sucesso', "Entrada registrada. Ordem {$ordem->numero} criada.");

        return $this->redirectRoute('ordens.show', $ordem);
    }

    public function render()
    {
        $user = auth()->user();
        $sugestoes = collect();
        if (! $this->cliente_id && (strlen($this->telefone) >= 8 || (! $this->sem_veiculo && strlen($this->placa) >= 7))) {
            $sugestoes = Cliente::ativos()->with('veiculos')
                ->where(fn ($q) => $q->when(strlen($this->telefone) >= 8, fn ($q) => $q->orWhere('telefone', $this->telefone))
                    ->when(strlen($this->placa) >= 7, fn ($q) => $q->orWhereHas('veiculos', fn ($v) => $v->where('placa', $this->placa))))
                ->limit(5)->get();
        }
        if (! $this->cliente_id && ($b = $this->buscaValida())) {
            $sugestoes = $sugestoes->concat(Cliente::ativos()->with('veiculos')->parecido($b)
                ->orderBy('nome')->limit(8)->get())->unique('id')->values();
        }

        return view('livewire.nova-entrada', [
            'unidades' => Unidade::visiveisPara($user)->where('ativa', true)->orderBy('nome')->get(),
            'sugestoes' => $sugestoes,
            'consultaDisponivel' => ! app(ConsultaPlaca::class) instanceof ConsultaPlacaIndisponivel,
            'veiculoExistente' => ! $this->sem_veiculo && strlen($this->placa) === 7 ? Veiculo::with('cliente')->where('placa', $this->placa)->first() : null,
        ]);
    }
}
