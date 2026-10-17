# Central de Projetos Git

Ferramenta local, em português do Brasil, para acompanhar projetos XAMPP com Git de forma segura. A interface foi inspirada no HTML de referência fornecido e evita que uma ação simples esconda uma operação destrutiva.

## Estado atual

- **Fase 1 concluída:** leitura real de `webe` e `webesolutions`, branch, commit, remoto, estado Git e classificação de alterações.
- **Fase 2 concluída:** seleção explícita de arquivos, stage, commit local, consulta (`fetch`) e envio (`push`) pelo Git configurado no computador.
- **Integração GitHub:** conexão de remoto HTTPS, token local criptografado, criação opcional de repositório vazio pela API, fetch e push com proteções contra sobrescrita de histórico.
- **Ainda não implementado:** OAuth, Pull Requests, Laravel Cloud, deploys, migrations remotas, rollback e auditoria persistente. Esses estados não são simulados.

## Segurança

- A Central só acessa as pastas listadas em `config/central.php`; não descobre nem altera outros projetos automaticamente.
- Por padrão, a Central aceita acessos somente de `localhost`, pois pode guardar token e executar Git. Mantenha `CENTRAL_LOCAL_ONLY=true`; só altere isso conscientemente em uma instalação protegida.
- Todo comando Git usa argumentos separados e um diretório canônico validado. Não há shell livre nem caminhos enviados pelo navegador.
- Nenhum `git add .`, `reset --hard`, force push, merge ou rebase é executado.
- `.env`, chaves (`.pem`, `.key`, `id_rsa`, `id_ed25519`) e `credentials.json` são bloqueados no stage/commit.
- `fetch`, stage, commit, integração de histórico e push exigem clique/confirmação explícitos. `fetch` atualiza apenas referências remotas; push só ocorre se não houver commits remotos pendentes.
- O token opcional do GitHub é criptografado com a chave da aplicação, armazenado fora da pasta pública e nunca é devolvido pela interface, pelo terminal didático ou pela API.
- Arquivos locais e sensíveis são ignorados pelo Git: `.env`, `storage/app/private/` (tokens criptografados) e `database/*.sqlite`.

## Instalação no Windows/XAMPP

1. Confirme PHP, Composer e Git: `php -v`, `composer --version`, `git --version`.
2. Em `C:\xampp\htdocs\centralprojetosgit`, instale dependências: `composer install`.
3. Gere a chave se necessário: `php artisan key:generate`.
4. Abra pelo Apache em `http://localhost/centralprojetosgit/public` ou execute `php artisan serve` para desenvolvimento.
5. Ajuste a lista autorizada em `config/central.php`. Não inclua a própria Central.

Para push, a própria Central conduz a criação do Git local, identificação do autor, conexão do remoto, integração segura de um README/LICENSE remoto e autenticação. Para repositórios HTTPS do GitHub, a aba **Publicações** permite salvar opcionalmente um token pessoal criptografado. Use um token granular limitado aos repositórios necessários: `Contents: Read and write` para consultar/enviar commits e `Administration: Write` apenas se a Central também for criar repositórios.

## Publicação do código

Este projeto pode ser publicado como um repositório público independente. Ele não deve ser criado dentro do repositório do produto que a Central acompanha. Antes de publicar, confirme que o `.gitignore` está preservado e que nenhum arquivo `.env`, banco local, token ou conteúdo de `storage/app/private/` foi incluído.

## Testes

```powershell
php artisan test
```

Os testes criam um repositório temporário em `storage/framework/testing-projects`; não usam `webe` nem `webesolutions`.

## Arquitetura atual

- `app/Services/LocalProjectReader.php`: allowlist, leitura Git e operações locais protegidas.
- `app/Http/Controllers/ProjectController.php`: API local para a interface.
- `resources/views/central.blade.php`: quatro abas, estados reais e ações progressivas.
- `config/central.php`: raiz e projetos explicitamente autorizados.

## Próximas fases

1. Integrar branches e Pull Requests.
2. Verificar documentação oficial do Laravel Cloud e criar adaptador apenas para recursos oficialmente disponíveis.
3. Separar migrations alteradas no código da execução confirmada por ambiente; incluir aprovações e auditoria para deploy/reversão.
