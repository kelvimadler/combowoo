# ComboWoo — Combos Dinâmicos para WooCommerce

Cria um novo tipo de produto **Combo** que agrupa produtos simples e/ou variáveis.
O combo é vendido como um produto único no front (com **preço, peso e dimensões próprios**).
No **pedido**, o **combo permanece com o preço total** e os **produtos componentes entram a
R$ 0,00** (cada um com seu SKU) — assim o WooCommerce baixa o estoque de cada componente e o
**Bling** enxerga o combo + os produtos individuais.

## Como funciona (fluxo)

```
Carrinho                          Pedido (o Bling lê isto)
────────                          ─────────────────────────
COMBO Verão   R$ 99,00     →      COMBO Verão       x1   R$ 99,00   (SKU COMBO-VER)
 └ Camiseta P                     Camiseta Preta P  x1   R$  0,00   (SKU CAM-P-PRE)
 └ Boné Azul                      Boné Azul         x1   R$  0,00   (SKU BON-AZL)
 └ Meia Branca                    Meia Branca       x1   R$  0,00   (SKU MEI-BRA)
                                  ── combo carrega o preço; filhos só baixam estoque ──
```

- **Preço todo no pai**: o combo mantém o preço total. Os produtos componentes entram a
  R$ 0,00, apenas para baixar o estoque de cada um.
- **Estoque derivado**: o combo fica disponível apenas se **todos** os componentes tiverem
  estoque suficiente. O combo não mantém estoque próprio no WooCommerce.
- **Baixa de estoque por produto**: como o pedido contém os produtos reais (mesmo a R$ 0,00), o
  WooCommerce (e o Bling, via SKU) baixa o estoque de cada componente automaticamente.

> **Importante para o Bling:** como o produto Combo aparece no pedido, ele precisa existir no
> Bling como **produto sem controle de estoque** (ou como *kit/composição*), senão o Bling
> tentará dar baixa de estoque do próprio combo. O controle de estoque real fica nos componentes.

## Instalação

1. Copie a pasta `COMBOWOO` para `wp-content/plugins/` (renomeie para `combowoo` se preferir).
2. Ative **ComboWoo** em *Plugins*.
3. É necessário ter o **WooCommerce** ativo.

## Como criar um combo

1. **Produtos → Adicionar novo**.
2. Em *Dados do produto*, selecione o tipo **Combo**.
3. Aba **Geral**: informe o **preço** do combo.
4. Aba **Entrega**: informe **peso, comprimento, largura e altura** do combo (usados no frete).
5. Aba **Combo**: adicione os produtos componentes.
   - **Produto simples**: basta selecionar e definir a quantidade.
   - **Produto variável**, duas opções:
     - **Vender variação específica** — você fixa a variação (ex.: Camiseta Preta P).
     - **Cliente escolhe a variação** — o cliente escolhe na página do produto.
6. Publique.

## Comportamento no front

- A página do combo mostra o **preço do combo** e, para cada componente marcado como
  *"cliente escolhe"*, um seletor de variação obrigatório.
- No carrinho aparece **uma linha** (o combo) com a lista *"Inclui: …"* dos componentes.

## Estoque do combo

O combo **não tem estoque próprio**: ele é sempre derivado dos componentes.

- **Um componente sem estoque = combo inteiro sem estoque.** A página mostra
  *"Fora de estoque"*, o formulário de compra não é exibido e a página informa quais itens
  acabaram. Na loja/arquivos o botão vira *"Sem estoque"*.
- **Quantidade máxima**: o combo permite no máximo `menor(estoque do componente ÷ quantidade
  usada no combo)` unidades. Ex.: componente A com 5 un. (1 por combo) e B com 3 un.
  (1 por combo) → no máximo **3 combos**. O campo de quantidade já sai limitado.
- **Componente com "cliente escolhe"**: basta **uma** variação disponível para o combo ficar
  em estoque; a variação escolhida é revalidada ao adicionar ao carrinho.
- **Componentes sem controle de estoque** ou **com encomenda permitida** não limitam o combo.
- **Sincronização automática**: sempre que o estoque de um componente muda (venda, admin,
  importação, Bling), o status de estoque gravado do combo é recalculado. Isso mantém a loja,
  o filtro *"ocultar produtos fora de estoque"*, os relatórios e a REST API corretos.
- **Revalidação no carrinho/checkout**: o combo é conferido de novo antes de finalizar, somando
  o que **todos** os itens do carrinho consomem de cada produto (combos + itens avulsos), para
  impedir venda acima do estoque.

## Compatibilidade

- **HPOS** (armazenamento de pedidos em tabelas próprias): compatível.
- **Checkout clássico** (shortcode) e **checkout em blocos / Store API**: ambos suportados.
- **Bling**: qualquer integração que leia os itens do pedido por **SKU** funciona, pois o
  pedido contém os produtos reais. Garanta que **cada produto/variação componente tenha SKU**.

## Observações e limites da v1

- **Impostos nativos do WooCommerce**: o preço fica todo no combo e os componentes entram a
  R$ 0,00. O ideal (e padrão no Brasil) é manter os impostos do WooCommerce **desativados** e
  tratar tributos no Bling/NF-e.
- **O combo aparece no pedido**: cadastre o combo no Bling como produto **sem controle de
  estoque** (ou *kit*). O estoque real é baixado pelos componentes (linhas a R$ 0,00).
- **Frete**: é calculado com o **peso e dimensões do combo** (definidos na aba *Entrega*), não
  pela soma dos componentes — que é o comportamento desejado.
- **Pedidos criados manualmente no admin**: a decomposição roda no checkout (loja). Para
  pedidos montados manualmente pelo admin, adicione os produtos individuais diretamente.
- **Personalizar o formulário**: copie
  `templates/single-product/add-to-cart/combo.php` para
  `seu-tema/woocommerce/single-product/add-to-cart/combo.php`.

## Estrutura de arquivos

```
combowoo.php                              Bootstrap, registro do tipo de produto
includes/
  class-wc-product-combo.php              Classe do produto (estoque derivado, textos)
  class-combo-admin.php                   Aba "Combo", salvamento e AJAX de variações
  class-combo-cart.php                    Dados do item, validação e exibição no carrinho
  class-combo-order.php                   Decomposição do pedido e rateio de preço
  class-combo-frontend.php                Formulário de compra no front
  class-combo-stock.php                   Sincroniza o estoque do combo com os componentes
  views/product-panel.php                 HTML do painel admin
assets/
  js/combo-admin.js                       UI dos componentes no admin
  css/combo-admin.css                     Estilos do admin
  css/combo-frontend.css                  Estilos do front
templates/
  single-product/add-to-cart/combo.php    Template do botão/seletores no produto
```
