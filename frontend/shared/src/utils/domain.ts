export type DomainChecker = (value: string) => boolean;

export type DomainValidatorCallback = (error?: Error) => void;

export const createDomainValidator = (isDomain: DomainChecker) => {
  return (
    _rule: unknown,
    value: string,
    callback: DomainValidatorCallback
  ): void => {
    callback(isDomain(value) ? undefined : new Error("请输入正确的域名格式"));
  };
};
