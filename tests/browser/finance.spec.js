import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

async function login(page) {
  await page.goto('/login')
  await page.getByLabel('E-mail').fill('owner@mycoins.local')
  await page.getByLabel('Senha').fill('Password!234')
  await page.getByRole('button', { name: /Entrar no My Coins/i }).click()
  await expect(page).toHaveURL(/dashboard/)
}

const importOfx = (fitIdSuffix, checkNumberSuffix = fitIdSuffix) => `OFXHEADER:100
DATA:OFXSGML
VERSION:102
SECURITY:NONE
ENCODING:USASCII
CHARSET:1252

<OFX>
<BANKID>0237
<CURDEF>BRL
<BANKTRANLIST>
<STMTTRN>
<TRNTYPE>CREDIT
<DTPOSTED>20260803000000[-03:EST]
<TRNAMT>1250.50
<FITID>PLAYWRIGHT-CREDIT-${fitIdSuffix}
<CHECKNUM>PW-CREDIT-${checkNumberSuffix}
<MEMO>Pagamento importado
</STMTTRN>
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260804000000[-03:EST]
<TRNAMT>-300.00
<FITID>PLAYWRIGHT-DEBIT-${fitIdSuffix}
<CHECKNUM>PW-DEBIT-${checkNumberSuffix}
<MEMO>Registro ignorado
</STMTTRN>
</BANKTRANLIST>
</OFX>`

const interOfx = fitIdSuffix => `OFXHEADER:100
DATA:OFXSGML
VERSION:102
ENCODING:USASCII
CHARSET:1252

<OFX>
<BANKID>077</BANKID>
<CURDEF>BRL</CURDEF>
<BANKTRANLIST>
<STMTTRN><TRNTYPE>PAYMENT</TRNTYPE><DTPOSTED>20260805000000[-03:EST]</DTPOSTED><TRNAMT>-45.90</TRNAMT><FITID>INTER-${fitIdSuffix}</FITID><CHECKNUM>077</CHECKNUM><NAME>Pagamento</NAME><REFNUM>REF-${fitIdSuffix}</REFNUM><MEMO>Compra Inter importada</MEMO></STMTTRN>
</BANKTRANLIST>
</OFX>`

test('login, navigation and persisted transaction flow are usable', async ({ page }, testInfo) => {
  const description = `Café com amigos ${testInfo.project.name}`
  await login(page)
  await expect(page.getByRole('heading', { name: /Sua vida financeira/i })).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Fluxo de caixa diário' })).toBeVisible()
  const dailyChart = page.locator('[data-apexchart-currency="BRL"]')
  await expect(dailyChart).toHaveAttribute('data-apexchart-ready', 'true')
  await expect(dailyChart.locator('.apexcharts-bar-series .apexcharts-series')).toHaveCount(2)
  await expect(dailyChart.locator('.apexcharts-line-series .apexcharts-series')).toHaveCount(1)
  await page.getByRole('link', { name: /Nova transação/i }).click()
  await page.getByLabel('Descrição').fill(description)
  await page.getByLabel('Valor').fill('25,90')
  await page.getByLabel('Conta').selectOption('1')
  await page.getByLabel('Categoria').selectOption({ label: 'Lazer e compras' })
  await page.getByRole('button', { name: /Adicionar transação/i }).click()
  await expect(page.getByRole('heading', { name: description })).toBeVisible()
  await page.getByRole('link', { name: /Abrir menu do usuário/i }).click()
  await page.getByRole('button', { name: /Sair/i }).click()
  await login(page)
  await page.goto(`/transactions?search=${encodeURIComponent(description)}`)
  await expect(page.getByText(description)).toBeVisible()
})

test('account balance date is visible and saved', async ({ page }, testInfo) => {
  const accountName = `Conta datada ${testInfo.project.name}`
  await login(page)
  await page.goto('/accounts/create')
  const balanceDate = page.getByLabel('Data do saldo inicial')
  await expect(balanceDate).not.toHaveValue('')
  await expect(page.getByText('O saldo informado representa o início deste dia.')).toBeVisible()
  await expect(page.getByText(/os saldos, a evolução e os indicadores podem não ser apresentados corretamente/i)).toBeVisible()
  await page.getByLabel('Nome da conta').fill(accountName)
  await page.getByLabel('Instituição').fill('Banco Exemplo')
  await page.locator('#opening_balance').fill('500,00')
  await balanceDate.fill('2026-08-15')
  await page.getByRole('button', { name: 'Adicionar conta' }).click()

  await expect(page.getByRole('heading', { name: accountName })).toBeVisible()
  await expect(page.getByText('15/08/2026')).toBeVisible()
})

test('tags can be created inline, filtered and managed', async ({ page }, testInfo) => {
  const tagName = `Aprendizado ${testInfo.project.name.replace('-chromium', '')}`
  const renamedTag = `Estudos ${testInfo.project.name.replace('-chromium', '')}`
  await login(page)
  await page.goto('/transactions/create')
  await page.getByLabel('Descrição').fill('Curso de finanças')
  await page.getByLabel('Valor').fill('199,90')
  await page.getByLabel('Conta').selectOption('1')
  await page.getByLabel('Categoria').selectOption({ label: 'Lazer e compras' })
  const tagInput = page.locator('.ts-control input').first()
  await tagInput.fill(tagName)
  await page.locator('.ts-dropdown .create').click()
  await page.getByRole('button', { name: /Adicionar transação/i }).click()
  await expect(page.getByText(`#${tagName}`)).toBeVisible()

  await page.goto('/transactions')
  const filterInput = page.locator('.ts-control input').first()
  await filterInput.fill(tagName)
  await page.locator('.ts-dropdown').getByRole('option', { name: tagName }).click()
  await page.getByRole('button', { name: /Aplicar filtros/i }).click()
  await expect(page.getByText('Curso de finanças')).toBeVisible()

  await page.goto('/tags')
  const tagRow = page.getByRole('row').filter({ hasText: `#${tagName}` })
  await tagRow.getByRole('link', { name: new RegExp(`Renomear ${tagName}`, 'i') }).click()
  await page.getByLabel('Nome').fill(renamedTag)
  await page.getByRole('button', { name: /Salvar alterações/i }).click()
  await expect(page.getByText(`#${renamedTag}`)).toBeVisible()
})

test('financial reports can be filtered by tag', async ({ page }) => {
  await login(page)
  await page.goto('/reports')

  const reportFilters = page.locator('form[method="get"]')
  const tagInput = reportFilters.locator('.ts-control input')
  await tagInput.fill('Fim de semana')
  await page.locator('.ts-dropdown').getByRole('option', { name: 'Fim de semana' }).click()
  await tagInput.press('Escape')
  await reportFilters.getByRole('button', { name: /Filtrar/i }).click()

  await expect(page).toHaveURL(/reports\?.*tags(%5B%5D|\[\])=3/)
  await expect(reportFilters.locator('.ts-control .item')).toContainText('Fim de semana')
  const details = page.locator('.card').filter({ has: page.getByRole('heading', { name: 'Detalhamento por categoria' }) })
  await expect(details.getByRole('row').filter({ hasText: 'Lazer e compras' })).toContainText('R$ 92,00')

  const accessibility = await new AxeBuilder({ page }).disableRules(['color-contrast']).analyze()
  expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact))).toEqual([])
})

test('transactions can be filtered by category', async ({ page }) => {
  await login(page)
  await page.goto('/transactions')

  const filters = page.locator('form[method="get"]')
  await filters.getByLabel('Categoria').selectOption({ label: 'Alimentação' })
  await filters.getByRole('button', { name: 'Aplicar filtros' }).click()

  await expect(page).toHaveURL(/transactions\?.*category_id=\d+/)
  await expect(filters.getByLabel('Categoria')).toHaveValue(/\d+/)
  const movements = page.locator('table')
  await expect(movements.getByText('Supermercado Vila')).toBeVisible()
  await expect(movements.getByText('Aluguel')).toHaveCount(0)
  await expect(movements.getByText('Reserva mensal')).toHaveCount(0)

  const accessibility = await new AxeBuilder({ page }).disableRules(['color-contrast']).analyze()
  expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact))).toEqual([])
})

test('transfer menu opens the dedicated linked-account flow', async ({ page }) => {
  await login(page)
  if ((page.viewportSize()?.width || 0) < 992) {
    await page.getByRole('button', { name: 'Alternar menu lateral' }).click()
  }
  const mainNavigation = page.getByRole('navigation', { name: 'Main navigation' })
  await mainNavigation.getByRole('link', { name: 'Transferir' }).click()
  await expect(page.getByRole('heading', { name: 'Transferir entre contas' })).toBeVisible()
  await page.getByLabel('Conta de origem').selectOption('1')
  await page.getByLabel('Conta de destino').selectOption('2')
  await page.getByLabel('Valor').fill('125,50')
  await page.getByLabel('Descrição').fill('Reserva para viagem')
  await page.getByRole('button', { name: /Confirmar transferência/i }).click()
  await expect(page.getByRole('heading', { name: 'Reserva para viagem' })).toBeVisible()
  await expect(page.getByText('Conta de origem')).toBeVisible()
  await expect(page.getByText('Conta de destino')).toBeVisible()

  await page.getByRole('link', { name: 'Editar transferência' }).click()
  await page.getByLabel('Descrição').fill('Reserva de férias')
  await page.getByRole('button', { name: /Salvar alterações/i }).click()
  await expect(page.getByRole('heading', { name: 'Reserva de férias' })).toBeVisible()
})

test('automatic categorization rules can be configured', async ({ page }) => {
  await login(page)
  await page.goto('/category-mappings')
  await expect(page.getByRole('heading', { name: 'Categorização automática' })).toBeVisible()
  const workCard = page.locator('section').filter({ has: page.getByRole('heading', { name: 'Trabalho', exact: true }) })
  const keywordInput = workCard.locator('.ts-control input')
  await keywordInput.fill('Pagamento importado')
  await keywordInput.press('Enter')
  await workCard.getByRole('button', { name: 'Salvar termos' }).click()
  await expect(page.getByText('Palavras-chave atualizadas com sucesso.')).toBeVisible()
  await expect(workCard.locator('.ts-control .item', { hasText: 'Pagamento importado' })).toBeVisible()
})

test('OFX wizard uploads, classifies and imports transactions', async ({ page }, testInfo) => {
  const suffix = testInfo.project.name.toUpperCase()
  const label = `Importação ${testInfo.project.name.replace('-chromium', '')}`
  await login(page)
  await page.goto('/transactions/import')
  await page.getByLabel('Banco do arquivo').selectOption('bradesco')
  await page.getByLabel('Conta').selectOption('1')
  const labelInput = page.locator('.ts-control input').first()
  await labelInput.fill(label)
  await page.locator('.ts-dropdown .create').click()
  await expect(page.locator('#label')).toHaveValue(label)
  await labelInput.press('Escape')
  const fileInput = page.getByLabel('Arquivo OFX')
  await fileInput.setInputFiles({
    name: 'agosto.ofx',
    mimeType: 'application/x-ofx',
    buffer: Buffer.from(importOfx(suffix)),
  })
  expect(await page.locator('form[action$="/preview"]').evaluate(form => form.checkValidity())).toBe(true)
  await Promise.all([
    page.waitForURL(/transactions\/import\/review/),
    page.locator('form[action$="/preview"]').evaluate(form => form.submit()),
  ])

  await expect(page.getByRole('heading', { name: /Revise as movimentações/i })).toBeVisible()
  await expect(page.getByText('Pagamento importado', { exact: true })).toBeVisible()
  await expect(page.getByText('Sugerida automaticamente')).toBeVisible()
  await expect(page.getByLabel('Categoria de Pagamento importado')).toHaveValue(/\d+/)
  const accessibility = await new AxeBuilder({ page }).disableRules(['color-contrast']).analyze()
  expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact))).toEqual([])

  const ignoredRow = page.getByRole('row').filter({ hasText: 'Registro ignorado' })
  await ignoredRow.getByRole('switch', { name: 'Ignorar' }).check({ force: true })
  await page.locator('form[data-import-review]').evaluate(form => form.submit())

  await expect(page.getByRole('heading', { name: 'Importação concluída' })).toBeVisible()
  await expect(page.getByText(`#${label}`)).toBeVisible()
  await page.getByRole('link', { name: 'Ver transações importadas' }).click()
  await expect(page.getByText('Pagamento importado')).toBeVisible()

  await page.goto('/transactions/import')
  await page.getByLabel('Banco do arquivo').selectOption('bradesco')
  await page.getByLabel('Conta').selectOption('1')
  const repeatedLabel = `${label} repetida`
  const repeatedLabelInput = page.locator('.ts-control input').first()
  await repeatedLabelInput.fill(repeatedLabel)
  await page.locator('.ts-dropdown .create').click()
  await repeatedLabelInput.press('Escape')
  await page.getByLabel('Arquivo OFX').setInputFiles({
    name: 'agosto-atualizado.ofx',
    mimeType: 'application/x-ofx',
    buffer: Buffer.from(importOfx(`REEXPORT-${suffix}`, suffix)),
  })
  await page.locator('form[action$="/preview"]').evaluate(form => form.submit())
  const duplicateRow = page.getByRole('row').filter({ hasText: 'Pagamento importado' })
  await expect(duplicateRow.getByText('Já importada')).toBeVisible()
  await expect(duplicateRow.getByLabel('Categoria de Pagamento importado')).toBeDisabled()
})

test('Inter OFX payment reaches the classification step as an expense', async ({ page }, testInfo) => {
  const suffix = `PW-${testInfo.project.name.toUpperCase()}`
  await login(page)
  await page.goto('/transactions/import')
  await page.getByLabel('Banco do arquivo').selectOption('inter')
  await page.getByLabel('Conta').selectOption('1')
  const labelInput = page.locator('.ts-control input').first()
  await labelInput.fill(`Inter ${testInfo.project.name}`)
  await page.locator('.ts-dropdown .create').click()
  await labelInput.press('Escape')
  await page.getByLabel('Arquivo OFX').setInputFiles({
    name: 'inter.ofx',
    mimeType: 'application/x-ofx',
    buffer: Buffer.from(interOfx(suffix)),
  })
  await Promise.all([
    page.waitForURL(/transactions\/import\/review/),
    page.locator('form[action$="/preview"]').evaluate(form => form.submit()),
  ])
  await expect(page.getByText('Banco Inter', { exact: true })).toBeVisible()
  const row = page.getByRole('row').filter({ hasText: 'Compra Inter importada' })
  await expect(row.getByText('Despesa', { exact: true })).toBeVisible()
  await expect(row.getByText('R$ -45,90')).toBeVisible()
})

for (const path of ['/login', '/dashboard', '/transactions', '/transactions/import', '/transfers/create', '/accounts', '/accounts/create', '/categories', '/category-mappings', '/tags', '/budgets', '/reports']) {
  test(`${path} has no serious accessibility violations`, async ({ page }) => {
    if (path !== '/login') await login(page)
    await page.goto(path)
    const results = await new AxeBuilder({ page }).disableRules(['color-contrast']).analyze()
    expect(results.violations.filter(v => ['serious', 'critical'].includes(v.impact))).toEqual([])
  })
}
