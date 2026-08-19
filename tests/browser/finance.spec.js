import AxeBuilder from '@axe-core/playwright'
import { expect, test } from '@playwright/test'

async function login(page) {
  await page.goto('/login')
  await page.getByRole('button', { name: /Entrar no My Coins/i }).click()
  await expect(page).toHaveURL(/dashboard/)
}

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

for (const path of ['/login', '/dashboard', '/transactions', '/budgets', '/reports']) {
  test(`${path} has no serious accessibility violations`, async ({ page }) => {
    if (path !== '/login') await login(page)
    await page.goto(path)
    const results = await new AxeBuilder({ page }).disableRules(['color-contrast']).analyze()
    expect(results.violations.filter(v => ['serious', 'critical'].includes(v.impact))).toEqual([])
  })
}
