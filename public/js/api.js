// Fetches JSON from the server. Throws on network errors and non-2xx answers.
export async function getJson(path) {
  const response = await fetch(path, { cache: 'no-store', credentials: 'same-origin' });
  if (!response.ok) throw new Error(`HTTP ${response.status}`);
  return response.json();
}
