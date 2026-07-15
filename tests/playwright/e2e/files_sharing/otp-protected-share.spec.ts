/*
 * SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getContainer, runOcc } from '@nextcloud/e2e-test-server'
import { runExec } from '@nextcloud/e2e-test-server/docker'
import { test } from '../../support/fixtures/files-sharing-page.ts'
import { expect } from '../../support/matchers.ts'
import { uploadContent } from '../../support/utils/dav.ts'
import {
	ALL_PERMISSIONS,
	createShare,
	SharePermission,
	ShareType,
} from '../../support/utils/sharing.ts'

test.describe.configure({ mode: 'serial' })

test.beforeAll(async () => {
	// install otp_provider_debug for testing otp protected shares
	const container = getContainer()
	const checkInstalledResult = await runExec(['bash', '-c', 'test -e apps-cypress/otp_provider_debug || echo "otp_provider_debug not installed"'])
	if (checkInstalledResult.indexOf('otp_provider_debug not installed') !== -1) {
		await runExec([
			'git',
			'clone',
			'--depth=1',
			'--branch=main',
			'https://github.com/theCalcaholic/otp_provider_debug.git',
			'apps-cypress/otp_provider_debug',
		], {
			container,
			verbose: true,
		})
	}
	await runOcc(['app:disable', 'otp_provider_debug'], { container, verbose: true })
	process.stdout.write('└ Ensured otp_provider_debug is installed\n')
})

test.describe('files_sharing/otp', () => {
	test('otp not visible without enabled OTP providers', async ({ page, user }) => {
		await runOcc(['app:disable', 'otp_provider_email'])
		await runOcc(['app:disable', 'otp_provider_debug'])
		const fileId = await uploadContent(page.request, user, 'testdata', 'text/plain', '/file.txt')

		await page.goto(`/index.php/apps/files/files/${fileId}?opendetails=true`)
		await page.locator('li:has-text("Create public link") button:has(.icon-add)').click()
		await expect(page.locator('.toastify:has-text("Link share created")')).toBeVisible()

		await page.locator('li:has-text("Share link") button:has-text("View only")').click()
		await expect(page.locator('button:has-text("Custom permissions")')).toBeVisible()
		await page.locator('button:has-text("Custom permissions")').click()
		await expect(page.locator('fieldset:has-text("No password"):has-text("Password")')).toBeVisible()
		await expect(page.locator('fieldset:has-text("OTP")')).not.toBeVisible()
	})

	// happy path
	test('create and retrieve OTP protected share', async ({ page, user }) => {
		const filename = 'file.txt'
		await runOcc(['app:enable', 'otp_provider_debug'])
		const fileId = await uploadContent(page.request, user, Buffer.alloc(0), 'text/plain', `/${filename}`)

		await page.goto(`/index.php/apps/files/files/${fileId}?opendetails=true`)
		await page.locator('li:has-text("Create public link") button:has(.icon-add)').click()
		await expect(page.locator('.toastify:has-text("Link share created")')).toBeVisible()

		await page.locator('li:has-text("Share link") button:has-text("View only")').click()
		await expect(page.locator('button:has-text("Custom permissions")')).toBeVisible()
		await page.locator('button:has-text("Custom permissions")').click()
		await expect(page.locator('fieldset:has-text("No password"):has-text("Password")')).toBeVisible()
		const otpButton = page.locator('fieldset:has-text("Authentication") *:text("OTP")')
		await expect(otpButton).toBeVisible()

		await otpButton.click()

		const otpProviderSelect = page.locator('fieldset:has-text("One-Time Password") div.v-select:has-text("method") input[type=search]')
		await expect(otpProviderSelect).toBeVisible()
		await otpProviderSelect.click()
		const otpProviderOption = page.locator('ul[aria-label=Options] li:has-text("Nextcloud Logs")')
		await expect(otpProviderOption).toBeVisible()
		await otpProviderOption.click()

		const otpRecipientInput = page.locator('fieldset:has-text("One-Time Password") div:has(label:has-text("Recipient")) input[type=text]')
		await otpRecipientInput.fill('testrecipient')

		await (page.locator('button:has-text("Update share")')).click()

		await expect(page.locator('.toastify:has-text("Share saved")')).toBeVisible()

		const shareUrl = await page.locator('a[aria-label=\'Copy public link of \\"Share link\\"\']').getAttribute('href')
		expect(shareUrl).toMatch(/http.*\/s\/.*/)

		await page.goto(shareUrl!)
		const otpRequestButton = page.locator('button:has-text("Request One-Time Password")')
		await expect(otpRequestButton).toBeVisible()
		await otpRequestButton.click()
		await page.waitForTimeout(10_000)
		await expect(page.locator('*:has-text("Request One-Time Password") .check-icon')).toBeVisible({ timeout: 10_000 })

		const otpProviderLogs = await runExec(['bash', '-c', 'cat data/nextcloud.log | grep \'"app":"otp_provider_debug"\''])
		const otpPwRegex = /"message":"OTP password sent: '(?<pw>.*)'"/g
		const matches = Array.from(otpProviderLogs.matchAll(otpPwRegex))
		const otpPass = matches.at(matches.length - 1)?.groups?.pw
		expect(otpPass).not.toBeUndefined()
		await page.locator(':has(label:has-text("Password")) input[type=password]').fill(otpPass!)
		await page.locator('button:has-text("Submit")').click()

		await expect(async () => {
			await expect(page.locator('body')).toContainText(filename, { timeout: 2_000 })
		}).toPass({ timeout: 30_000 })
	})

	test('missing otp provider shows error on retrieval', async ({ page, owner, ownerRequest }) => {
		const filename = 'file.txt'
		await runOcc(['app:enable', 'otp_provider_debug'])
		await uploadContent(ownerRequest, owner, Buffer.alloc(0), 'text/plain', `/${filename}`)
		const ocs = await createShare(ownerRequest, `/${filename}`, undefined, ALL_PERMISSIONS & ~SharePermission.CREATE & ~SharePermission.DELETE, ShareType.LINK, undefined, 'debug', 'foobar')
		const shareUrl = ocs.data.url as string
		await runOcc(['app:disable', 'otp_provider_debug'])
		expect(shareUrl).toMatch(/http.*\/s\/.*/)

		await page.goto(shareUrl!)
		await page.waitForTimeout(5_000)
		await expect(page.locator('body'))
			.toContainText('This share requires a one-time password, but the configured one-time password provider \'debug\' could not be found')
	})

	test('otp can\'t be used twice', async ({ page, owner, ownerRequest }) => {
		const filename = 'file.txt'
		await runOcc(['app:enable', 'otp_provider_debug'])
		await uploadContent(ownerRequest, owner, Buffer.alloc(0), 'text/plain', `/${filename}`)
		const ocs = await createShare(ownerRequest, `/${filename}`, undefined, ALL_PERMISSIONS & ~SharePermission.CREATE & ~SharePermission.DELETE, ShareType.LINK, undefined, 'debug', 'foobar')
		const shareUrl = ocs.data.url as string
		expect(shareUrl).toMatch(/http.*\/s\/.*/)

		await page.goto(shareUrl!)
		const otpRequestButton = page.locator('button:has-text("Request One-Time Password")')
		await expect(otpRequestButton).toBeVisible()
		await otpRequestButton.click()
		await page.waitForTimeout(10_000)
		await expect(page.locator('*:has-text("Request One-Time Password") .check-icon')).toBeVisible({ timeout: 10_000 })

		const otpProviderLogs = await runExec(['bash', '-c', 'cat data/nextcloud.log | grep \'"app":"otp_provider_debug"\''])
		const otpPwRegex = /"message":"OTP password sent: '(?<pw>.*)'"/g
		const matches = Array.from(otpProviderLogs.matchAll(otpPwRegex))
		const otpPass = matches.at(matches.length - 1)?.groups?.pw
		expect(otpPass).not.toBeUndefined()
		await page.locator(':has(label:has-text("Password")) input[type=password]').fill(otpPass!)
		await page.locator('button:has-text("Submit")').click()

		await expect(async () => {
			await expect(page.locator('body')).toContainText(filename, { timeout: 2_000 })
		}).toPass({ timeout: 30_000 })

		await page.context().clearCookies()

		await page.goto(shareUrl)
		await page.reload()
		await page.locator(':has(label:has-text("Password")) input[type=password]').fill(otpPass!)
		await page.locator('button:has-text("Submit")').click()
		await expect(async () => {
			await expect(page.locator('body'))
				.toContainText('The password is wrong or expired. Please try again or request a new one.')
		}).toPass({ timeout: 30_000 })
	})
})
