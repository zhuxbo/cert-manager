interface Http {
  get(url: string, config?: any): Promise<any>;
  post(url: string, data?: any, config?: any): Promise<any>;
  put(url: string, config?: any): Promise<any>;
  delete(url: string, config?: any): Promise<any>;
  request(method: string, url: string, config?: any): Promise<any>;
}

const http: Http = {
  get: (url, config) => (window as any).__deps.http.get(url, config),
  post: (url, data, config) =>
    (window as any).__deps.http.post(url, { data, ...config }),
  put: (url, config) => (window as any).__deps.http.request("put", url, config),
  delete: (url, config) => (window as any).__deps.http.delete(url, config),
  request: (method, url, config) =>
    (window as any).__deps.http.request(method, url, config)
};

export { http };
