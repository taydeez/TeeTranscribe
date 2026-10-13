import { defineRailway, github, project, service } from 'railway/iac';

// Own only backend application services; existing databases and secrets stay managed in Railway.
export const partial = 'teetranscribe-backend';

// These names must match the services shown in the target Railway environment.
export const environments = {
  production: {
    branch: 'deployment',
    dockerfile: 'Dockerfile.production',
    services: {
      api: 'api',
      general: 'general',
      ingestion: 'ingestion',
      processing: 'processing',
      dubbing: 'dubbing',
      scheduler: 'scheduler',
    },
  },
  staging: {
    branch: 'staging',
    dockerfile: 'Dockerfile.staging',
    services: {
      api: 'tee-transcribe-api',
      'staging-workers': 'tee-transcribe-worker',
    },
  },
} as const;

const drainingSeconds: Record<string, number> = {
  api: 30,
  general: 930,
  ingestion: 930,
  processing: 7230,
  dubbing: 5430,
  scheduler: 60,
  'staging-workers': 7230,
};

export default defineRailway((ctx) => {
  const environment = ctx.environment?.toLowerCase();
  if (environment !== 'production' && environment !== 'staging') {
    throw new Error('Select the production or staging Railway environment before planning this configuration.');
  }
  if (ctx.projectId !== '52cdb407-7aeb-4402-8530-3a92c583b4a3') {
    throw new Error('Link the CLI to the TeeTranscribe Railway project before planning this configuration.');
  }

  const profile = environments[environment];
  const names = Object.values(profile.services);
  if (new Set(names).size !== names.length) {
    throw new Error('Each backend role must have a different Railway service name.');
  }

  return project(ctx.projectName ?? 'TeeTranscribe', {
    resources: Object.entries(profile.services).map(([role, name]) => service(name, {
      source: github('taydeez/TeeTranscribe', {
        branch: profile.branch,
        rootDirectory: '/backend',
      }),
      build: {
        builder: 'DOCKERFILE',
        dockerfilePath: profile.dockerfile,
        watchPatterns: ['/backend/**'],
      },
      deploy: {
        startCommand: '/usr/local/bin/start-service ' + role,
        preDeployCommand: environment === 'staging' && role === 'api'
          ? ['php artisan migrate --force --seed --no-interaction']
          : null,
        healthcheckPath: role === 'api' ? '/up' : null,
        healthcheckTimeout: role === 'api' ? 120 : null,
        restartPolicyType: 'ALWAYS',
        numReplicas: 1,
        drainingSeconds: drainingSeconds[role],
        sleepApplication: false,
      },
    })),
  });
});
