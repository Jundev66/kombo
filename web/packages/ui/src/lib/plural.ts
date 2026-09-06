/**
 * "1 producto", not "1 productos".
 *
 * Four lines for a grammar mistake that showed on six screens. It does not look
 * serious and it is: to an owner who sees "1 productos" on the screen they run
 * their business from, it says how much care went into the rest.
 *
 * No internationalisation library, on purpose: the product is in Spanish and
 * only in Spanish, so a plural engine would carry the abstraction of a problem
 * we do not have.
 */
export function plural(count: number, singular: string, plural: string): string {
  return `${count} ${count === 1 ? singular : plural}`
}
