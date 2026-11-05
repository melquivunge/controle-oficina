<?php

namespace App\Livewire;

use App\Models\Cliente;
use App\Models\Unidade;
use App\Models\Veiculo;
use App\Services\Entradas;
use App\Support\Fuso;
use App\Support\Normaliza;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithFileUploads;

class NovaEntrada extends Component
{
    use WithFileUploads;

    public ?int $unidade_id = null;
    public ?int $cliente_id = null;
    public string $nome = '';
    public string $telefone = '';
    public string $placa = '';
    public string $motivo = '';
    public string $modelo = '';
    public string $quilometragem = '';
    public string $observacoes = '';
    public array $fotos = [];
    public bool $contingencia = false;
    public string $horario_original = '';
    public string $contingencia_nota = '';
    public bool $confirmar_transferencia = false;
    // Entrada sem veículo/placa (peças avulsas, ex.: jogo de rodas) e endereço opcional do cliente.
    public bool $sem_veiculo = false;
    public string $item_descricao = '';
    public array $endereco = ['cep' => '', 'logradouro' => '', 'numero' => '', 'complemento' => '', 'bairro' => '', 'cidade' => '', 'uf' => ''];

    public function mount(): void
    {
        $this->authorize('registrar_entrada');
        $user = auth()->user();
        $this->unidade_id = $user->visaoGlobal() ? null : $user->unidade_id;
        // Pré-preenchimento vindo de outra tela (ex.: Central de WhatsApp); o cadastro existente é sugerido pela busca normal.
        $this->telefone = Normaliza::telefone((string) request()->query('telefone', ''));
        $this->nome = mb_substr(trim((string) request()->query('nome', '')), 0, 150);
    }

    protected function rules(): array
    {
        $user = auth()->user();

        return [
            'unidade_id' => ['required', 'integer', Rule::in(Unidade::visiveisPara($user)->where('ativa', true)->pluck('id')->all())],
            'cliente_id' => ['nullable', 'integer', Rule::exists('clientes', 'id')->whereNull('mesclado_em_id')],
            'nome' => ['required', 'string', 'min:2', 'max:150'],
            'telefone' => ['required', 'regex:/^\d{10,11}$/'],
            'sem_veiculo' => ['boolean'],
            'placa' => ['exclude_if:sem_veiculo,true', 'required', 'regex:'.Normaliza::REGEX_PLACA],
            'item_descricao' => ['exclude_unless:sem_veiculo,true', 'required', 'string', 'min:3', 'max:200'],
            'motivo' => ['required', 'string', 'min:3', 'max:2000'],
            'modelo' => ['exclude_if:sem_veiculo,true', 'nullable', 'string', 'max:80'],
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
            'endereco.uf' => ['nullable', Rule::in(\App\Support\Endereco::UFS)],
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
            'placa.regex' => 'Placa inválida. Use o formato ABC1234 ou ABC1D23.',
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

    public function updatedPlaca(): void
    {
        $this->placa = Normaliza::placa($this->placa);
    }

    public function usarCliente(int $id): void
    {
        // Só cadastros encontrados pelo telefone ou placa digitados (sem enumeração por id).
        $cliente = Cliente::ativos()->whereKey($id)->where(fn ($q) => $q->where('telefone', $this->telefone)
            ->orWhereHas('veiculos', fn ($v) => $v->where('placa', $this->placa)))->firstOrFail();
        $this->cliente_id = $cliente->id;
        $this->nome = $cliente->nome;
        $this->telefone = $cliente->telefone;
    }

    public function salvar(Entradas $entradas)
    {
        $this->telefone = Normaliza::telefone($this->telefone);
        $this->placa = $this->sem_veiculo ? '' : Normaliza::placa($this->placa);
        $this->endereco = array_map(fn ($v) => (string) $v, \App\Support\Endereco::normalizar($this->endereco));
        if (! auth()->user()->can('ver_contato_cliente')) {
            $this->endereco = array_fill_keys(array_keys($this->endereco), '');
        }
        $dados = $this->validate();
        $dados['sem_veiculo'] = $this->sem_veiculo;
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

        return view('livewire.nova-entrada', [
            'unidades' => Unidade::visiveisPara($user)->where('ativa', true)->orderBy('nome')->get(),
            'sugestoes' => $sugestoes,
            'veiculoExistente' => ! $this->sem_veiculo && strlen($this->placa) === 7 ? Veiculo::with('cliente')->where('placa', $this->placa)->first() : null,
        ]);
    }
}
