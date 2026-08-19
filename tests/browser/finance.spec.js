import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

async function login(page) {
  await page.goto('/login')
  await page.getByRole('button', { name: /Entrar no My Coins/i }).click()
  await expect(page).toHaveURL(/dashboard/)
}

const importOfx = `OFXHEADER:100
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
<FITID>PLAYWRIGHT-CREDIT-001
<MEMO>Pagamento importado
</STMTTRN>
<STMTTRN>
<TRNTYPE>DEBIT
<DTPOSTED>20260804000000[-03:EST]
<TRNAMT>-300.00
<FITID>PLAYWRIGHT-DEBIT-001
<MEMO>Registro ignorado
</STMTTRN>
</BANKTRANLIST>
</OFX>`

test('login, navigation, transaction flow and reset are usable', async ({ page }) => {
  await login(page)
  await expect(page.getByRole('heading', { name: /Sua vida financeira/i })).toBeVisible()
  await page.getByRole('link', { name: /Nova transação/i }).click()
  await page.getByLabel('Descrição').fill('Café com amigos')
  await page.getByLabel('Valor').fill('25,90')
  await page.getByLabel('Conta').selectOption('1')
  await page.getByLabel('Categoria').selectOption('6')
  await page.getByRole('button', { name: /Adicionar transação/i }).click()
  await expect(page.getByRole('heading', { name: 'Café com amigos' })).toBeVisible()
  await page.goto('/transactions?search=Café')
  await expect(page.getByText('Café com amigos')).toBeVisible()
  page.on('dialog', dialog => dialog.accept())
  await page.goto('/dashboard')
  await page.getByRole('button', { name: /Restaurar dados/i }).click()
  await expect(page.getByText(/dados de demonstração foram restaurados/i)).toBeVisible()
})

test('tags can be created inline, filtered and managed', async ({ page }) => {
  await login(page)
  await page.goto('/transactions/create')
  await page.getByLabel('Descrição').fill('Curso de finanças')
  await page.getByLabel('Valor').fill('199,90')
  await page.getByLabel('Conta').selectOption('1')
  await page.getByLabel('Categoria').selectOption('6')
  const tagInput = page.locator('.ts-control input').first()
  await tagInput.fill('Aprendizado')
  await page.locator('.ts-dropdown .create').click()
  await page.getByRole('button', { name: /Adicionar transação/i }).click()
  await expect(page.getByText('#Aprendizado')).toBeVisible()

  await page.goto('/transactions')
  const filterInput = page.locator('.ts-control input').first()
  await filterInput.fill('Aprendizado')
  await page.locator('.ts-dropdown').getByRole('option', { name: 'Aprendizado' }).click()
  await page.getByRole('button', { name: /Aplicar filtros/i }).click()
  await expect(page.getByText('Curso de finanças')).toBeVisible()

  await page.goto('/tags')
  const tagRow = page.getByRole('row').filter({ hasText: '#Aprendizado' })
  await tagRow.getByRole('link', { name: /Renomear Aprendizado/i }).click()
  await page.getByLabel('Nome').fill('Estudos')
  await page.getByRole('button', { name: /Salvar alterações/i }).click()
  await expect(page.getByText('#Estudos')).toBeVisible()
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

test('OFX wizard uploads, classifies and imports transactions', async ({ page }) => {
  await login(page)
  await page.goto('/transactions/import')
  await page.getByLabel('Conta').selectOption('1')
  const labelInput = page.locator('.ts-control input').first()
  await labelInput.fill('Importação Playwright')
  await page.locator('.ts-dropdown .create').click()
  await expect(page.locator('#label')).toHaveValue('Importação Playwright')
  await labelInput.press('Escape')
  const fileInput = page.getByLabel('Arquivo OFX')
  await fileInput.setInputFiles({
    name: 'agosto.ofx',
    mimeType: 'application/x-ofx',
    buffer: Buffer.from(importOfx),
  })
  expect(await page.locator('form[action$="/preview"]').evaluate(form => form.checkValidity())).toBe(true)
  await Promise.all([
    page.waitForURL(/transactions\/import\/review/),
    page.locator('form[action$="/preview"]').evaluate(form => form.submit()),
  ])

  await expect(page.getByRole('heading', { name: /Revise as movimentações/i })).toBeVisible()
  await expect(page.getByText('Pagamento importado', { exact: true })).toBeVisible()
  const accessibility = await new AxeBuilder({ page }).disableRules(['color-contrast']).analyze()
  expect(accessibility.violations.filter(v => ['serious', 'critical'].includes(v.impact))).toEqual([])

  await page.getByLabel('Categoria de Pagamento importado').selectOption('1')
  const ignoredRow = page.getByRole('row').filter({ hasText: 'Registro ignorado' })
  await ignoredRow.getByRole('switch', { name: 'Ignorar' }).check({ force: true })
  await page.locator('form[data-import-review]').evaluate(form => form.submit())

  await expect(page.getByRole('heading', { name: 'Importação concluída' })).toBeVisible()
  await expect(page.getByText('#Importação Playwright')).toBeVisible()
  await page.getByRole('link', { name: 'Ver transações importadas' }).click()
  await expect(page.getByText('Pagamento importado')).toBeVisible()
})

for (const path of ['/login', '/dashboard', '/transactions', '/transactions/import', '/transfers/create', '/tags', '/budgets', '/reports']) {
  test(`${path} has no serious accessibility violations`, async ({ page }) => {
    if (path !== '/login') await login(page)
    await page.goto(path)
    const results = await new AxeBuilder({ page }).disableRules(['color-contrast']).analyze()
    expect(results.violations.filter(v => ['serious', 'critical'].includes(v.impact))).toEqual([])
  })
}
