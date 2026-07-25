export const buildEndpointUrl = (
  path: string,
  origin = window.location.origin
) => new URL(path, origin).href;
