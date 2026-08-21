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

test('login, navigation and persisted transaction flow are usable', async ({ page }, testInfo) => {
  const description = `Café com amigos ${testInfo.project.name}`
  await login(page)
  await expect(page.getByRole('heading', { name: /Sua vida financeira/i })).toBeVisible()
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

for (const path of ['/login', '/dashboard', '/transactions', '/transactions/import', '/transfers/create', '/categories', '/category-mappings', '/tags', '/budgets', '/reports']) {
  test(`${path} has no serious accessibility violations`, async ({ page }) => {
    if (path !== '/login') await login(page)
    await page.goto(path)
    const results = await new AxeBuilder({ page }).disableRules(['color-contrast']).analyze()
    expect(results.violations.filter(v => ['serious', 'critical'].includes(v.impact))).toEqual([])
  })
}
