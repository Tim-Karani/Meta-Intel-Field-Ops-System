import { api } from './api.js';

/* Cached picker data. Short TTL so new records appear without a reload, while repeated modals stay instant. */
const cache = new Map();
const TTL = 30000;

export async function lookup(entity, params = {}) {
  const key = `${entity}?${JSON.stringify(params)}`;
  const hit = cache.get(key);
  if (hit && Date.now() - hit.at < TTL) return hit.value;
  const { data } = await api.get(`lookups/${entity}`, params);
  const value = data.map((r) => ({ value: r.id, label: r.name }));
  cache.set(key, { at: Date.now(), value });
  return value;
}
export const invalidateLookups = () => cache.clear();

let enumsPromise = null;
export function enums() {
  enumsPromise ??= api.get('lookups/enums').then((r) => r.data).catch((e) => { enumsPromise = null; throw e; });
  return enumsPromise;
}
export const enumOptions = async (key) => (await enums())[key] || [];
