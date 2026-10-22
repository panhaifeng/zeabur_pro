-- 头像落库（可选；接口也会自动尝试 ALTER）
ALTER TABLE `user`
  ADD COLUMN `avatar_bin` MEDIUMBLOB NULL COMMENT '头像二进制' AFTER `avatar`;
