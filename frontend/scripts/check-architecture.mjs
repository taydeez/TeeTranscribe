import { readFile, readdir, stat } from 'node:fs/promises'
import { dirname, extname, join, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const frontendRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..')
const appRoot = join(frontendRoot, 'app')
const failures = []

const requiredDirectories = [
  'components',
  'composables',
  'config',
  'layouts',
  'middleware',
  'pages',
  'stores',
  'types',
]

async function exists(path) {
  try {
    await stat(path)
    return true
  } catch {
    return false
  }
}

async function collectFiles(directory) {
  const entries = await readdir(directory, { withFileTypes: true })
  const files = []

  for (const entry of entries) {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) {
      files.push(...await collectFiles(path))
    } else {
      files.push(path)
    }
  }

  return files
}

function report(path, message) {
  failures.push(`${relative(frontendRoot, path)}: ${message}`)
}

for (const directory of requiredDirectories) {
  const path = join(appRoot, directory)
  if (!await exists(path)) {
    report(path, 'required architecture directory is missing')
  }
}

const appShellPath = join(appRoot, 'app.vue')
const appShell = await readFile(appShellPath, 'utf8')
const appShellLines = appShell.split(/\r?\n/).length

if (!appShell.includes('<NuxtLayout>') || !appShell.includes('<NuxtPage')) {
  report(appShellPath, 'the root shell must render NuxtLayout and NuxtPage')
}

if (appShellLines > 40) {
  report(appShellPath, `the root shell has ${appShellLines} lines; keep it at 40 or fewer`)
}

for (const forbidden of ['<script', '<style', '$fetch', 'useRoute(', 'useRouter(', 'definePageMeta(']) {
  if (appShell.includes(forbidden)) {
    report(appShellPath, `the root shell must not contain ${forbidden}`)
  }
}

const sourceFiles = (await collectFiles(appRoot))
  .filter(path => ['.ts', '.vue'].includes(extname(path)))

for (const path of sourceFiles) {
  const source = await readFile(path, 'utf8')
  const lines = source.split(/\r?\n/).length
  const relativePath = relative(appRoot, path).replaceAll('\\', '/')

  if ((relativePath.startsWith('pages/') || relativePath.startsWith('components/')) && lines > 250) {
    report(path, `contains ${lines} lines; split page and component workflows before exceeding 250 lines`)
  }

  if (relativePath.startsWith('composables/') && lines > 300) {
    report(path, `contains ${lines} lines; split the composable by responsibility before exceeding 300 lines`)
  }

  if ((relativePath.startsWith('pages/') || relativePath.startsWith('components/')) && /\broute\.path\b/.test(source)) {
    report(path, 'manual route-path matching is forbidden; use NuxtLink active state or route metadata')
  }

  if ((relativePath.startsWith('pages/') || relativePath.startsWith('components/')) && /\b(activePage|currentPageComponent)\b/.test(source)) {
    report(path, 'local page-switching state is forbidden; represent screens with Nuxt routes')
  }

  if (relativePath.startsWith('pages/dashboard/') && !source.includes("layout: 'dashboard'")) {
    report(path, "dashboard pages must declare definePageMeta({ layout: 'dashboard' })")
  }
}

if (failures.length > 0) {
  console.error('Frontend architecture check failed:\n')
  for (const failure of failures) console.error(`- ${failure}`)
  process.exitCode = 1
} else {
  console.log(`Frontend architecture check passed (${sourceFiles.length} source files checked).`)
}
