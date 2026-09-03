<template>
  <div class="user-info">
    <el-form ref="formItem" :rules="ruleValidate" :model="formItem" label-width="100px" @submit.native.prevent>
      <div class="section">
        <div class="section-hd">基本信息</div>
        <div class="section-bd">
          <div class="item">
            <el-form-item label="用户ID：">
              <el-input class="form-sty" disabled v-model="formItem.uid" placeholder="请输入编号"></el-input>
            </el-form-item>
          </div>
          <div class="item">
            <el-form-item label="真实姓名：" prop="real_name">
              <el-input class="form-sty" v-model.trim="formItem.real_name" placeholder="请输入真实姓名"></el-input>
            </el-form-item>
          </div>
          <div class="item">
            <el-form-item label="手机号码：" prop="phone">
              <el-input class="form-sty" v-model="formItem.phone" placeholder="请输入手机号码">
                <el-select v-model="formItem.dial_code" slot="prepend" style="width: 110px">
                  <el-option
                    v-for="item in countryList"
                    :key="item.dial_code"
                    :value="item.dial_code"
                    :label="'+' + item.dial_code + ' ' + item.name"
                  ></el-option>
                </el-select>
              </el-input>
            </el-form-item>
          </div>
          <div class="item">
            <el-form-item label="生日：">
              <el-date-picker
                clearable
                class="form-sty"
                type="date"
                v-model="formItem.birthday"
                placeholder="请选择生日"
                format="yyyy-MM-dd"
                value-format="yyyy-MM-dd"
              ></el-date-picker>
            </el-form-item>
          </div>
          <div class="item">
            <el-form-item label="身份证号：">
              <el-input class="form-sty" v-model.trim="formItem.card_id" placeholder="请输入身份证号"></el-input>
            </el-form-item>
          </div>
          <div class="item">
            <el-form-item label="用户地址：">
              <el-input class="form-sty" v-model="formItem.addres" placeholder="请输入用户地址"></el-input>
            </el-form-item>
          </div>
        </div>
      </div>
      <div class="section">
        <div class="section-hd">密码</div>
        <div class="section-bd">
          <div class="item">
            <el-form-item label="登录密码：" prop="pwd">
              <el-input
                class="form-sty"
                type="password"
                v-model="formItem.pwd"
                placeholder="请输入登录密码（修改用户可不填写，不填写不修改原密码）"
              ></el-input>
            </el-form-item>
          </div>
          <div class="item">
            <el-form-item label="确认密码：" prop="true_pwd">
              <el-input
                class="form-sty"
                type="password"
                v-model="formItem.true_pwd"
                placeholder="请输入确认密码（修改用户可不填写，不填写不修改原密码）"
              ></el-input>
            </el-form-item>
          </div>
        </div>
      </div>
      <div class="section">
        <div class="section-hd">用户概况</div>
        <div class="section-bd">
          <div class="item">
            <el-form-item label="用户等级：">
              <el-select v-model="formItem.level" class="form-sty" clearable>
                <el-option
                  v-for="(item, index) in infoData.levelInfo"
                  :key="index"
                  :value="item.id"
                  :label="item.name"
                ></el-option>
              </el-select>
            </el-form-item>
          </div>
          <div class="item">
            <el-form-item label="用户分组：">
              <el-select v-model="formItem.group_id" class="form-sty" clearable>
                <el-option
                  v-for="(item, index) in infoData.groupInfo"
                  :key="index"
                  :value="item.id"
                  :label="item.group_name"
                ></el-option>
              </el-select>
            </el-form-item>
          </div>
          <div class="item lang">
            <el-form-item label="用户标签：">
              <div style="display: flex">
                <div class="labelInput acea-row row-between-wrapper" v-db-click @click="openLabel">
                  <div style="width: 90%">
                    <div v-if="dataLabel.length">
                      <el-tag
                        closable
                        v-for="(item, index) in dataLabel"
                        :key="index"
                        @close="closeLabel(item)"
                        class="mr10"
                        >{{ item.label_name }}</el-tag
                      >
                    </div>
                    <span class="span" v-else>选择用户关联标签</span>
                  </div>
                  <div class="ivu-icon ivu-icon-ios-arrow-down"></div>
                </div>
                <span class="addfont" v-db-click @click="addLabel">新增标签</span>
              </div>
            </el-form-item>
          </div>
          <div class="item lang">
            <el-form-item label="分销禁用：">
              <el-radio-group v-model="formItem.spread_open" class="form-sty">
                <el-radio :label="0">是</el-radio>
                <el-radio :label="1">否</el-radio>
              </el-radio-group>
              <div class="tip">禁用用户的分销资格后，在任何分销模式下该用户都无分销权限</div>
            </el-form-item>
          </div>
          <div class="item lang" v-if="formItem.spread_open == 1">
            <el-form-item label="分销权限：">
              <el-radio-group v-model="formItem.is_promoter" class="form-sty">
                <el-radio :label="1">开启</el-radio>
                <el-radio :label="0">关闭</el-radio>
              </el-radio-group>
              <div class="tip">手动开启或关闭用户的分销权限</div>
            </el-form-item>
          </div>
          <div class="item lang">
            <el-form-item label="用户状态：">
              <el-radio-group v-model="formItem.status" class="form-sty">
                <el-radio :label="1">开启</el-radio>
                <el-radio :label="0">锁定</el-radio>
              </el-radio-group>
            </el-form-item>
          </div>
        </div>
      </div>
      <div class="section">
        <div class="section-hd">用户备注</div>
        <div class="section-bd">
          <div class="item">
            <el-form-item label="用户备注：">
              <el-input
                class="form-sty"
                type="textarea"
                :rows="5"
                v-model="formItem.mark"
                placeholder="请输入用户备注"
              ></el-input>
            </el-form-item>
          </div>
        </div>
      </div>
    </el-form>
    <el-dialog :visible.sync="labelShow" append-to-body title="请选择用户标签" :show-close="true" width="540px">
      <userLabel v-if="labelShow" :only_get="true" :uid="formItem.uid" @close="labelClose" @activeData="activeData">
      </userLabel>
    </el-dialog>
  </div>
</template>

<script>
import userLabel from '@/components/userLabel';

import { userLabelAddApi, getUserInfo, editUser, setUser } from '@/api/user';
import dayjs from 'dayjs';

export default {
  name: 'userInfo',
  components: { userLabel },
  props: {
    userId: {
      type: Number,
      default: 0,
    },
  },
  filters: {
    timeFormat(value) {
      if (!value) {
        return '-';
      }
      return dayjs(value * 1000).format('YYYY-MM-DD HH:mm:ss');
    },
    gender(value) {
      if (value == 1) {
        return '男';
      } else if (value == 2) {
        return '女';
      } else {
        return '未知';
      }
    },
  },
  data() {
    return {
      labelShow: false,
      countryList: [
        { dial_code: '86', name: '中国' },
        { dial_code: '81', name: '日本' },
        { dial_code: '82', name: '韩国' },
        { dial_code: '886', name: '台湾' },
        { dial_code: '852', name: '香港' },
        { dial_code: '65', name: '新加坡' },
        { dial_code: '1', name: '美国/加拿大' },
        { dial_code: '44', name: '英国' },
        { dial_code: '49', name: '德国' },
        { dial_code: '33', name: '法国' },
        { dial_code: '39', name: '意大利' },
        { dial_code: '34', name: '西班牙' },
        { dial_code: '31', name: '荷兰' },
        { dial_code: '41', name: '瑞士' },
        { dial_code: '46', name: '瑞典' },
        { dial_code: '61', name: '澳大利亚' },
      ],
      formItem: {
        uid: 0,
        real_name: '',
        phone: '',
        dial_code: '86',
        birthday: '',
        card_id: '',
        addres: '',
        mark: '',
        pwd: '',
        true_pwd: '',
        level: '',
        group_id: '',
        label_id: [],
        spread_open: 0,
        is_promoter: 0,
        status: 1,
      },
      groupInfo: [],
      labelInfo: [],
      levelInfo: [],
      infoData: {
        groupInfo: [],
        labelInfo: [],
        levelInfo: [],
      },
      ruleValidate: {
        real_name: [{ required: true, message: ' ', trigger: 'blur' }],
        phone: [{ required: true, message: ' ', trigger: 'blur' }],
        pwd: [{ required: true, message: ' ', trigger: 'blur' }],
        true_pwd: [{ required: true, message: ' ', trigger: 'blur' }],
      },
      dataLabel: [],
    };
  },
  computed: {
    hasExtendInfo() {
      //   return this.psInfo.extend_info.some((item) => item.value);
    },
  },
  created() {
    this.getUserFrom(this.userId);

    // this.formItem = this.userData.userInfo;
  },
  methods: {
    setUser() {
      let data = this.formItem;
      let ids = [];
      this.dataLabel.map((i) => {
        ids.push(i.id);
      });
      data.label_id = ids;
      // if (!data.real_name) return this.$message.warning("请输入真实姓名");
      // if (!data.phone) return this.$message.warning("请输入手机号");
      // if (!data.pwd) return this.$message.warning("请输入密码");
      // if (!data.true_pwd) return this.$message.warning("请输入确认密码");
      if (data.uid) {
        editUser(data)
          .then((res) => {
            this.$message.success(res.msg);
            this.$emit('success');
          })
          .catch((err) => {
            this.$message.error(err.msg);
          });
      } else {
        setUser(data)
          .then((res) => {
            this.$emit('success');
            this.$message.success(res.msg);
          })
          .catch((err) => {
            this.$message.error('err.msg');
          });
      }
    },
    addLabel() {
      this.$modalForm(userLabelAddApi(0)).then(() => {});
    },
    openLabel(row) {
      this.labelShow = true;
      this.$refs.userLabel.userLabel(JSON.parse(JSON.stringify(this.infoData.labelInfo)));
    },
    closeLabel(label) {
      let index = this.dataLabel.indexOf(this.dataLabel.filter((d) => d.id == label.id)[0]);
      this.dataLabel.splice(index, 1);
    },
    getUserFrom(id) {
      getUserInfo(id)
        .then(async (res) => {
          this.userData = res.data;
          this.$set(this.infoData, 'groupInfo', this.userData.groupInfo);
          this.$set(this.infoData, 'levelInfo', this.userData.levelInfo);
          this.$set(this.infoData, 'labelInfo', this.userData.labelInfo);
          let arr = Object.keys(this.formItem);
          if (this.userData.userInfo) {
            arr.map((i) => {
              this.formItem[i] = this.userData.userInfo[i];
            });
            this.splitPhone(this.userData.userInfo.phone);
            if (!this.formItem.birthday) this.formItem.birthday = '';
            if (this.formItem.label_id.length) {
              this.dataLabel = this.formItem.label_id;
            }
          } else {
            this.reset();
          }
        })
        .catch((res) => {
          this.$message.error('res.msg');
        });
    },
    // 保存形式の電話番号を dial_code / phone（国内表記）へ分解して表示用にセットする
    // 中国番号（86）は従来通り "+" なしの国内表記で保存されているためそのまま表示する
    splitPhone(phone) {
      phone = phone || '';
      if (phone.charAt(0) !== '+') {
        this.formItem.dial_code = '86';
        this.formItem.phone = phone;
        return;
      }
      const digits = phone.slice(1);
      const sorted = [...this.countryList].sort((a, b) => String(b.dial_code).length - String(a.dial_code).length);
      const matched = sorted.find((c) => digits.indexOf(String(c.dial_code)) === 0);
      if (matched) {
        this.formItem.dial_code = matched.dial_code;
        this.formItem.phone = digits.slice(String(matched.dial_code).length);
      } else {
        this.formItem.dial_code = '86';
        this.formItem.phone = phone;
      }
    },
    // 标签弹窗关闭
    labelClose() {
      this.labelShow = false;
    },
    activeData(dataLabel) {
      this.labelShow = false;
      this.dataLabel = dataLabel;
    },
    reset() {
      this.formItem = {
        uid: '',
        real_name: '',
        phone: '',
        dial_code: '86',
        birthday: '',
        card_id: '',
        addres: '',
        mark: '',
        pwd: '',
        true_pwd: '',
        level: '',
        group_id: '',
        label_id: [],
        spread_open: 0,
        is_promoter: 0,
        status: 1,
      };
    },
  },
};
</script>

<style lang="scss" scoped>
.labelInput {
  border: 1px solid #dcdee2;
  width: 300px;
  padding: 0 15px;
  border-radius: 5px;
  min-height: 30px;
  cursor: pointer;
  font-size: 12px;

  .span {
    color: #c5c8ce;
  }

  .iconxiayi {
    font-size: 12px;
  }
}

.width-add {
  width: 40px;
}

.mr30 {
  margin-right: 30px;
}

.user-info {
  .section {
    padding: 25px 0 0;
    border-bottom: 1px dashed #eeeeee;

    &-hd {
      margin-bottom: 18px;
      padding-left: 10px;
      border-left: 3px solid var(--prev-color-primary);
      font-weight: 500;
      font-size: 14px;
      line-height: 16px;
      color: #303133;
    }

    &-bd {
      display: flex;
      flex-wrap: wrap;
    }

    .item {
      width: 50%;
      display: flex;
      font-size: 13px;
      color: #666;

      .form-sty {
        width: 300px;
      }

      .ivu-form-item {
        margin: 3px 0;
      }

      .addfont {
        display: inline-block;
        font-size: 12px;
        font-weight: 400;
        color: var(--prev-color-primary);
        margin-left: 14px;
        cursor: pointer;
        margin-left: 10px;
      }
    }

    .item.lang {
      width: 100%;
    }

    .value {
      flex: 1;
    }

    .avatar {
      width: 60px;
      height: 60px;
      overflow: hidden;

      img {
        width: 100%;
        height: 100%;
      }
    }
  }
}
</style>
